<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\Common\Persistence\ObjectManager;
use elFinder;
use elFinderConnector;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class DefaultController extends Controller
{
    /**
     * @param \Doctrine\Common\Persistence\ObjectManager $em
     * @param int                                        $user_id
     *
     * @return array
     */
    private function getDataFromToken(ObjectManager $em, int $user_id): array
    {
        $provider = new GenericProvider([
            'clientId'                => getenv('OAUTH_CLIENT_ID'),
            'clientSecret'            => getenv('OAUTH_CLIENT_SECRET'),
            'redirectUri'             => getenv('OAUTH_REDIRECT_URI'),
            'scopes'                  => 'user:email user:username user:id',
            'urlAuthorize'            => getenv('OAUTH_URL_AUTHORIZE'),
            'urlAccessToken'          => getenv('OAUTH_URL_ACCESS_TOKEN'),
            'urlResourceOwnerDetails' => getenv('OAUTH_URL_RESOURCE'),
        ]);

        /** @var User $user */
        $user = $em->getRepository(User::class)->findOneBy(['remote_id' => $user_id]);
        /** @var \League\OAuth2\Client\Token\AccessToken $accessToken */
        $accessToken = unserialize($user->getTokenData());

        if ($accessToken->hasExpired()) {
            $accessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $accessToken->getRefreshToken(),
            ]);

            // Purge old access token and store new access token to your data store.
            /** @var User $user */
            $user = $em->getRepository(User::class)->findOneBy(['remote_id' => $user_id]);
            $user->setTokenData(serialize($accessToken));
            $em->flush();
        }

        $resourceOwner = $provider->getResourceOwner($accessToken);
        return $resourceOwner->toArray();
    }

    public function index(SessionInterface $session)
    {
        if (!$session->has('LOGIN')) {
            return $this->redirectToRoute('login');
        } else {
            return $this->redirectToRoute('files');
        }
    }

    public function login(Request $request, SessionInterface $session)
    {
        $provider = new GenericProvider([
            'clientId'                => getenv('OAUTH_CLIENT_ID'),
            'clientSecret'            => getenv('OAUTH_CLIENT_SECRET'),
            'redirectUri'             => getenv('OAUTH_REDIRECT_URI'),
            'scopes'                  => 'user:email user:username user:id',
            'urlAuthorize'            => getenv('OAUTH_URL_AUTHORIZE'),
            'urlAccessToken'          => getenv('OAUTH_URL_ACCESS_TOKEN'),
            'urlResourceOwnerDetails' => getenv('OAUTH_URL_RESOURCE'),
        ]);

        // If we don't have an authorization code then get one
        if (!$request->query->has('code')) {

            // Fetch the authorization URL from the provider; this returns the
            // urlAuthorize option and generates and applies any necessary parameters
            // (e.g. state).
            $authorizationUrl = $provider->getAuthorizationUrl();

            // Get the state generated for you and store it to the session.
            $session->set('oauth2state', $provider->getState());

            // Redirect the user to the authorization URL.
            return new RedirectResponse($authorizationUrl);

            // Check given state against previously stored one to mitigate CSRF attack
        } elseif (empty($request->query->get('state')) || ($session->has('oauth2state') && $request->query->get('state') !== $session->get('oauth2state'))) {

            if ($session->has('oauth2state')) {
                $session->remove('oauth2state');
            }

            return new Response('Invalid state. <a href="'.$this->generateUrl('login').'">Try again</a>');

        } else {

            try {

                // Try to get an access token using the authorization code grant.
                $accessToken = $provider->getAccessToken('authorization_code', [
                    'code' => $request->query->get('code'),
                ]);

                // Using the access token, we may look up details about the
                // resource owner.
                $resourceOwner = $provider->getResourceOwner($accessToken);
                $data = $resourceOwner->toArray();

                $em = $this->getDoctrine()->getManager();
                /** @var User $user */
                $user = $em->getRepository(User::class)->findOneBy(['remote_id' => $data['id']]);
                if (is_null($user)) {
                    $user = (new User())
                        ->setRemoteId($data['id'])
                        ->setUsername($data['username'])
                        ->setEmail($data['email'])
                        ->setTokenData(serialize($accessToken));
                    $em->persist($user);
                } else {
                    $user->setTokenData(serialize($accessToken));
                }
                $em->flush();

                // TODO: Find a better way for login
                $session->set('LOGIN', $user->getRemoteId());

                return new RedirectResponse($this->generateUrl('files'));

            } catch (IdentityProviderException $e) {

                // Failed to get the access token or user details.
                return new Response('<pre>Error: '.$e->getMessage().'</pre>');

            }

        }
    }

    public function files(SessionInterface $session)
    {
        if (!$session->has('LOGIN')) {
            return $this->redirectToRoute('login');
        }

        return $this->render('files.html.twig');
    }

    public function showRawFile(SessionInterface $session, $file)
    {
        $em = $this->getDoctrine()->getManager();

        if (!$session->has('LOGIN')) {
            return $this->redirectToRoute('login');
        }

        $data = $this->getDataFromToken($em, $session->get('LOGIN'));
        /** @var User $user */
        $user = $em->getRepository(User::class)->findOneBy(['remote_id' => $data['id']]);

        $extensionToMime = [
            'pdf'  => 'application/pdf',
            'zip'  => 'application/zip',
            'gif'  => 'image/gif',
            'jpg'  => 'image/jpeg',
            'png'  => 'image/png',
            'css'  => 'text/css',
            'html' => 'text/html',
            'js'   => 'text/javascript',
            'txt'  => 'text/plain',
            'xml'  => 'text/xml',
        ];

        $fileDir = $this->get('kernel')->getProjectDir().'/var/data/storage/'.$user->getRemoteId().'/'.$file;
        $fileInfo = pathinfo($fileDir);

        $response = new Response();
        $response->setContent(file_get_contents($fileDir));
        $response->headers->set('Content-Type', $extensionToMime[$fileInfo['extension']]);

        return $response;
    }

    public function connector(SessionInterface $session)
    {
        $em = $this->getDoctrine()->getManager();

        if (!$session->has('LOGIN')) {
            return $this->json([]);
        }
        $data = $this->getDataFromToken($em, $session->get('LOGIN'));
        /** @var User $user */
        $user = $em->getRepository(User::class)->findOneBy(['remote_id' => $data['id']]);

        // Create directories
        $rootCloudDir = $this->get('kernel')->getProjectDir().'/var/data/storage/'.$user->getRemoteId();
        if (!file_exists($rootCloudDir)) {
            mkdir($rootCloudDir, 0777, true);
        }
        if (!file_exists($rootCloudDir.'/.trash/')) {
            mkdir($rootCloudDir.'/.trash/', 0777, true);
        }

        // ===============================================
        // Enable FTP connector netmount
        elFinder::$netDrivers['ftp'] = 'FTP';
        // ===============================================
        // // Enable network mount
        elFinder::$netDrivers['dropbox2'] = 'Dropbox2';
        // // Dropbox2 Netmount driver need next two settings. You can get at https://www.dropbox.com/developers/apps
        // // AND reuire regist redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=dropbox2&host=1"
        define('ELFINDER_DROPBOX_APPKEY',    getenv('ELFINDER_DROPBOX_APPKEY'));
        define('ELFINDER_DROPBOX_APPSECRET', getenv('ELFINDER_DROPBOX_APPSECRET'));
        // ===============================================
        // // Enable network mount
        elFinder::$netDrivers['googledrive'] = 'GoogleDrive';
        // // GoogleDrive Netmount driver need next two settings. You can get at https://console.developers.google.com
        // // AND reuire regist redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=googledrive&host=1"
        define('ELFINDER_GOOGLEDRIVE_CLIENTID',     getenv('ELFINDER_GOOGLEDRIVE_CLIENTID'));
        define('ELFINDER_GOOGLEDRIVE_CLIENTSECRET', getenv('ELFINDER_GOOGLEDRIVE_CLIENTSECRET'));
        // ===============================================
        // // Required for One Drive network mount
        // //  * cURL PHP extension required
        // //  * HTTP server PATH_INFO supports required
        // // Enable network mount
        elFinder::$netDrivers['onedrive'] = 'OneDrive';
        // // GoogleDrive Netmount driver need next two settings. You can get at https://dev.onedrive.com
        // // AND reuire regist redirect url to "YOUR_CONNECTOR_URL/netmount/onedrive/1"
        define('ELFINDER_ONEDRIVE_CLIENTID',     getenv('ELFINDER_ONEDRIVE_CLIENTID'));
        define('ELFINDER_ONEDRIVE_CLIENTSECRET', getenv('ELFINDER_ONEDRIVE_CLIENTSECRET'));
        // ===============================================
        // // Required for Box network mount
        // //  * cURL PHP extension required
        // // Enable network mount
        elFinder::$netDrivers['box'] = 'Box';
        // // Box Netmount driver need next two settings. You can get at https://developer.box.com
        // // AND reuire regist redirect url to "YOUR_CONNECTOR_URL"
        define('ELFINDER_BOX_CLIENTID',     getenv('ELFINDER_BOX_CLIENTID'));
        define('ELFINDER_BOX_CLIENTSECRET', getenv('ELFINDER_BOX_CLIENTSECRET'));
        // ===============================================

        // Documentation for connector options:
        // https://github.com/Studio-42/elFinder/wiki/Connector-configuration-options
        /**
         * Simple function to demonstrate how to control file access using "accessControl" callback.
         * This method will disable accessing files/folders starting from '.' (dot)
         *
         * @param  string    $attr    attribute name (read|write|locked|hidden)
         * @param  string    $path    absolute file path
         * @param  string    $data    value of volume option `accessControlData`
         * @param  object    $volume  elFinder volume driver object
         * @param  bool|null $isDir   path is directory (true: directory, false: file, null: unknown)
         * @param  string    $relpath file path relative to volume root directory started with directory separator
         *
         * @return bool|null
         **/
        $accessFunction = function (string $attr, string $path, $data, $volume, $isDir, string $relpath) {
            $basename = basename($path);
            return $basename[0] === '.'                  // if file/folder begins with '.' (dot)
                && strlen($relpath) !== 1                // but with out volume root
                ? !($attr == 'read' || $attr == 'write') // set read+write to false, other (locked+hidden) set to true
                : null;                                  // else elFinder decide it itself
        };
        $opts = [
            // 'debug' => true,
            'roots' => [

                // Items volume
                [
                    'alias'         => 'Home',
                    'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
                    'path'          => realpath($rootCloudDir), // path to files (REQUIRED)
                    'URL'           => '/h/',                       // URL to files (REQUIRED)
                    'trashHash'     => 't1_Lw',                     // elFinder's hash of trash folder
                    'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
                    'uploadDeny'    => ['all'],                     // All Mimetypes not allowed to upload
                    'uploadAllow'   => ['image', 'text/plain'],     // Mimetype `image` and `text/plain` allowed to upload
                    'uploadOrder'   => ['deny', 'allow'],           // allowed Mimetype `image` and `text/plain` only
                    'accessControl' => $accessFunction,             // disable and hide dot starting files (OPTIONAL)
                ],

                // Trash volume
                [
                    'id'            => '1',
                    'driver'        => 'Trash',
                    'path'          => realpath($rootCloudDir.'/.trash'),
                    'tmbURL'        => '../var/data/storage/'.$user->getRemoteId().'/.trash/.tmb',
                    'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
                    'uploadDeny'    => ['all'],                     // Recommend the same settings as the original volume that uses the trash
                    'uploadAllow'   => ['image', 'text/plain'],     // Same as above
                    'uploadOrder'   => ['deny', 'allow'],           // Same as above
                    'accessControl' => $accessFunction,             // Same as above
                ]
            ]
        ];

        // Run elFinder
        header('Access-Control-Allow-Origin: *');
        $connector = new elFinderConnector(new elFinder($opts));
        $connector->run();
        exit;
    }
}