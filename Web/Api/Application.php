<?php
/**
 * Orange Management
 *
 * PHP Version 7.2
 *
 * @package    Web\Api
 * @copyright  Dennis Eichhorn
 * @license    OMS License 1.0
 * @version    1.0.0
 * @link       http://website.orange-management.de
 */
namespace Web\Api;

use phpOMS\Auth\Auth;
use phpOMS\Auth\LoginReturnType;
use phpOMS\DataStorage\Database\DatabaseStatus;
use phpOMS\Message\Http\Request;
use phpOMS\Message\Http\Response;
use phpOMS\Message\Http\RequestStatusCode;
use phpOMS\System\MimeType;
use phpOMS\Views\View;
use phpOMS\Account\AccountManager;
use phpOMS\Account\Account;
use phpOMS\DataStorage\Database\DataMapperAbstract;
use phpOMS\DataStorage\Cache\CachePool;
use phpOMS\Event\EventManager;
use phpOMS\Uri\UriFactory;
use phpOMS\DataStorage\Database\DatabasePool;
use phpOMS\DataStorage\Session\HttpSession;
use phpOMS\Dispatcher\Dispatcher;
use phpOMS\Localization\L11nManager;
use phpOMS\Module\ModuleManager;
use phpOMS\Router\Router;

use Modules\Admin\Models\AccountMapper;
use Modules\Admin\Models\AccountPermissionMapper;
use Modules\Admin\Models\GroupPermissionMapper;

use Model\Message\Notify;
use Model\Message\NotifyType;
use Model\Message\Redirect;
use Model\Message\Reload;
use Model\CoreSettings;

use Web\WebApplication;

/**
 * Application class.
 *
 * @package    Web\Api
 * @license    OMS License 1.0
 * @link       http://website.orange-management.de
 * @since      1.0.0
 */
class Application
{
    /**
     * WebApplication.
     *
     * @var WebApplication
     * @since 1.0.0
     */
    private $app = null;

    /**
     * Temp config.
     *
     * @var array
     * @since 1.0.0
     */
    private $config = [];

    /**
     * Constructor.
     *
     * @param WebApplication $app    WebApplication
     * @param array          $config Application config
     *
     * @since  1.0.0
     */
    public function __construct(WebApplication $app, array $config)
    {
        $this->app          = $app;
        $this->app->appName = 'Api';
        $this->config       = $config;
        UriFactory::setQuery('/app', strtolower($this->app->appName));
    }

    /**
     * Rendering backend.
     *
     * @param Request  $request  Request
     * @param Response $response Response
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function run(Request $request, Response $response) : void
    {
        $response->getHeader()->set('Content-Type', 'text/plain; charset=utf-8');
        $pageView = new View($this->app, $request, $response);

        $this->app->l11nManager = new L11nManager();
        $this->app->dbPool      = new DatabasePool();
        $this->app->router      = new Router();
        $this->app->router->importFromFile(__DIR__ . '/Routes.php');

        $this->app->sessionManager = new HttpSession(36000);
        $this->app->moduleManager  = new ModuleManager($this->app, __DIR__ . '/../../Modules');
        $this->app->dispatcher     = new Dispatcher($this->app);

        $this->app->dbPool->create('core', $this->config['db']['core']['masters']['admin']);
        $this->app->dbPool->create('insert', $this->config['db']['core']['masters']['insert']);
        $this->app->dbPool->create('select', $this->config['db']['core']['masters']['select']);
        $this->app->dbPool->create('update', $this->config['db']['core']['masters']['update']);
        $this->app->dbPool->create('delete', $this->config['db']['core']['masters']['delete']);
        $this->app->dbPool->create('schema', $this->config['db']['core']['masters']['schema']);

        if ($this->app->dbPool->get()->getStatus() !== DatabaseStatus::OK) {
            $response->getHeader()->setStatusCode(RequestStatusCode::R_503);

            return;
        }

        /* Checking csrf token, if a csrf token is required at all has to be decided in the controller */
        if ($request->getData('CSRF') !== null && $this->app->sessionManager->get('CSRF') !== $request->getData('CSRF')) {
            $response->getHeader()->setStatusCode(RequestStatusCode::R_403);

            return;
        }

        DataMapperAbstract::setConnection($this->app->dbPool->get());

        $this->app->cachePool      = new CachePool();
        $this->app->appSettings    = new CoreSettings($this->app->dbPool->get());
        $this->app->eventManager   = new EventManager();
        $this->app->accountManager = new AccountManager($this->app->sessionManager);

        $aid = Auth::authenticate($this->app->sessionManager);
        $request->getHeader()->setAccount($aid);
        $response->getHeader()->setAccount($aid);

        // todo: only load options if no language specified?
        $options = $this->app->appSettings->get([1000000009, 1000000029]);
        $account = $this->loadAccount($request);

        $response->getHeader()->getL11n()->setLanguage(!\in_array($request->getHeader()->getL11n()->getLanguage(), $this->config['language']) ? $options[1000000029] : $request->getHeader()->getL11n()->getLanguage());
        UriFactory::setQuery('/lang', $response->getHeader()->getL11n()->getLanguage());
        $response->getHeader()->set('content-language', $response->getHeader()->getL11n()->getLanguage(), true);

        if (!empty($uris = $request->getUri()->getQuery('r'))) {
            $this->handleBatchRequest($uris, $request, $response);
        } else {
            if ($request->getUri()->getPathElement(2) === 'login' && $account->getId() < 1) {
                $this->handleLogin($request, $response);

                return;
            } elseif ($request->getUri()->getPathElement(2) === 'logout' && $request->getData('csrf') === $this->app->sessionManager->get('CSRF')) {
                $this->handleLogout($request, $response);

                return;
            }

            $this->app->moduleManager->initRequestModules($request);

            $dispatched = $this->app->dispatcher->dispatch($this->app->router->route($request), $request, $response);

            // todo: maybe better check if this and response is empty?!
            // this is sometimes getting called even on normal web views... maybe this is related to the service worker
            if (empty($dispatched)) {
                $response->getHeader()->setStatusCode(RequestStatusCode::R_404);
                $response->set($request->getUri()->__toString(), '');
            }

            $pageView->addData('dispatch', $dispatched);
        }
    }

    /**
     * Load permission
     *
     * @param Request $request Current request
     *
     * @return Account
     *
     * @since  1.0.0
     */
    private function loadAccount(Request $request) : Account
    {
        $this->app->accountManager->add(AccountMapper::get($request->getHeader()->getAccount()));
        $account = $this->app->accountManager->get($request->getHeader()->getAccount());

        $groupPermissions = GroupPermissionMapper::getFor(array_keys($account->getGroups()), 'group');
        $account->addPermissions(is_array($groupPermissions) ? $groupPermissions : [$groupPermissions]);

        $accountPermissions = AccountPermissionMapper::getFor($request->getHeader()->getAccount(), 'account');
        $account->addPermissions(is_array($accountPermissions) ? $accountPermissions : [$accountPermissions]);

        return $account;
    }

    /**
     * Handle batch requests
     *
     * @param string   $uris     Uris to handle
     * @param Request  $request  Request
     * @param Response $response Response
     *
     * @return void
     *
     * @since  1.0.0
     */
    private function handleBatchRequest(string $uris, Request $request, Response $response) : void
    {
        $request_r = clone $request;
        $uris      = \json_decode($uris, true);

        foreach ($uris as $key => $uri) {
            //$request_r->init($uri);

            $modules = $this->app->moduleManager->getRoutedModules($request_r);
            $this->app->moduleManager->initModule($modules);

            $this->app->dispatcher->dispatch($this->app->router->route($request), $request, $response);
        }
    }

    /**
     * Handle login request
     *
     * @param Request  $request  Request
     * @param Response $response Response
     *
     * @return void
     *
     * @since  1.0.0
     */
    private function handleLogin(Request $request, Response $response) : void
    {
        $response->getHeader()->set('Content-Type', MimeType::M_JSON . '; charset=utf-8', true);

        $login = AccountMapper::login((string) ($request->getData('user') ?? ''), (string) ($request->getData('pass') ?? ''));

        if ($login >= LoginReturnType::OK) {
            $this->app->sessionManager->set('UID', $login);
            $this->app->sessionManager->save();
            $response->set($request->getUri()->__toString(), new Reload());
        } else {
            $response->set($request->getUri()->__toString(), new Notify('Login failed due to wrong login information', NotifyType::INFO));
        }
    }

    /**
     * Handle logout request
     *
     * @param Request  $request  Request
     * @param Response $response Response
     *
     * @return void
     *
     * @since  1.0.0
     */
    private function handleLogout(Request $request, Response $response) : void
    {
        $response->getHeader()->set('Content-Type', MimeType::M_JSON . '; charset=utf-8', true);

        $this->app->sessionManager->remove('UID');
        $this->app->sessionManager->save();

        $response->set($request->getUri()->__toString(), new Redirect('http://www.google.de'));
    }
}
