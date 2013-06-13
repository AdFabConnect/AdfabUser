<?php
namespace AdfabUser\Authentication\Adapter;

use Hybrid_Auth;
use AdfabUser\Authentication\Adapter\Exception;
use AdfabUser\Mapper\UserProvider;
use AdfabUser\Options\ModuleOptions;
use Zend\Authentication\Result;
use Zend\ServiceManager\ServiceManagerAwareInterface;
use Zend\ServiceManager\ServiceManager;
use ZfcUser\Authentication\Adapter\AbstractAdapter;
use ZfcUser\Authentication\Adapter\AdapterChainEvent as AuthEvent;
use ZfcUser\Mapper\UserInterface as UserMapperInterface;
use ZfcUser\Options\UserServiceOptionsInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerAwareInterface;

class HybridAuth extends AbstractAdapter implements ServiceManagerAwareInterface, EventManagerAwareInterface
{
    /**
     * @var Hybrid_Auth
     */
    protected $hybridAuth;

    /**
     * @var ServiceManager
     */
    protected $serviceManager;

    /**
     * @var ModuleOptions
     */
    protected $options;

    /**
     * @var UserServiceOptionsInterface
     */
    protected $zfcUserOptions;

    /**
     * @var UserProviderInterface
     */
    protected $mapper;

    /**
     * @var UserMapperInterface
     */
    protected $zfcUserMapper;

    /**
     * @var EventManagerInterface
     */
    protected $events;

    /**
     * @var RoleMapperInterface
     */
    protected $roleMapper;

    public function authenticate(AuthEvent $authEvent)
    {
        if ($this->isSatisfied()) {
            $storage = $this->getStorage()->read();
            $authEvent->setIdentity($storage['identity'])
              ->setCode(Result::SUCCESS)
              ->setMessages(array('Authentication successful.'));

            return;
        }

        $enabledProviders = $this->getOptions()->getEnabledProviders();
        $provider = $authEvent->getRequest()->getQuery()->get('provider');

        if (empty($provider) || !in_array($provider, $enabledProviders)) {
            $authEvent->setCode(Result::FAILURE)
              ->setMessages(array('Invalid provider'));
            $this->setSatisfied(false);

            return false;
        }

        $userProfile = null;
        try {
            $adapter = $this->getHybridAuth()->authenticate($provider);
            if ($adapter->isUserConnected()) {
            	$userProfile = $adapter->getUserProfile();
            }
        } catch (\Exception $ex) {
        	// The following retry is efficient in case a user previously registered on his social account
        	// with the app has unsubsribed from the app
        	// cf http://hybridauth.sourceforge.net/userguide/HybridAuth_Sessions.html

        	if ( ($ex->getCode() == 6) || ($ex->getCode() == 7) ){
        		// Réinitialiser la session HybridAuth
        		$this->getHybridAuth()->getAdapter($provider)->logout();
        		// Essayer de se connecter à nouveau
        		$adapter = $this->getHybridAuth()->authenticate($provider);
        		if ($adapter->isUserConnected()) {
        			$userProfile = $adapter->getUserProfile();
        		}
        	} else{
        		$authEvent->setCode(Result::FAILURE)
        		->setMessages(array('Invalid provider'));
        		$this->setSatisfied(false);

        		return false;
        	}
        }

        if (!$userProfile) {
            $authEvent->setCode(Result::FAILURE_IDENTITY_NOT_FOUND)
              ->setMessages(array('A record with the supplied identity could not be found.'));
            $this->setSatisfied(false);

            return false;
        }

        $localUserProvider = $this->getMapper()->findUserByProviderId($userProfile->identifier, $provider);

        if (false == $localUserProvider && $this->getOptions()->getCreateUserAutoSocial()) {
            $method = $provider.'ToLocalUser';
            if (method_exists($this, $method)) {
                try {
                    $localUser = $this->$method($userProfile);
                } catch (Exception\RuntimeException $ex) {
                    $authEvent->setCode($ex->getCode())
                        ->setMessages(array($ex->getMessage()))
                        ->stopPropagation();
                    $this->setSatisfied(false);

                    return false;
                }
            } else {
                $localUser = $this->instantiateLocalUser();
                $localUser->setDisplayName($userProfile->displayName)
                          ->setPassword($provider);
                if ($userProfile->emailVerified) $localUser->setEmail($userProfile->emailVerified);
                $result = $this->insert($localUser, 'other', $userProfile);
            }

            $localUserProvider = new \AdfabUser\Entity\UserProvider();
            $localUserProvider->setUserId($localUser->getId())
                ->setProviderId($userProfile->identifier)
                ->setProvider($provider);
            $this->getMapper()->insert($localUserProvider);
        }

        $zfcUserOptions = $this->getZfcUserOptions();

        if ($zfcUserOptions->getEnableUserState()) {
            // Don't allow user to login if state is not in allowed list
            $mapper = $this->getZfcUserMapper();
            $user = $mapper->findById($localUserProvider->getUserId());
            if (!in_array($user->getState(), $zfcUserOptions->getAllowedLoginStates())) {
                $authEvent->setCode(Result::FAILURE_UNCATEGORIZED)
                  ->setMessages(array('A record with the supplied identity is not active.'));
                $this->setSatisfied(false);

                return false;
            }
        }

        $authEvent->setIdentity($localUserProvider->getUserId());

        $this->setSatisfied(true);
        $storage = $this->getStorage()->read();
        $storage['identity'] = $authEvent->getIdentity();
        $this->getStorage()->write($storage);
        $authEvent->setCode(Result::SUCCESS)
          ->setMessages(array('Authentication successful.'))
          ->stopPropagation();
    }

    /**
     * Get the Hybrid_Auth object
     *
     * @return Hybrid_Auth
     */
    public function getHybridAuth()
    {
        if (!$this->hybridAuth) {
            $this->hybridAuth = $this->getServiceManager()->get('HybridAuth');
        }

        return $this->hybridAuth;
    }

    /**
     * Set the Hybrid_Auth object
     *
     * @param  Hybrid_Auth    $hybridAuth
     * @return UserController
     */
    public function setHybridAuth(Hybrid_Auth $hybridAuth)
    {
        $this->hybridAuth = $hybridAuth;

        return $this;
    }

    /**
     * Retrieve service manager instance
     *
     * @return ServiceManager
     */
    public function getServiceManager()
    {
        return $this->serviceManager;
    }

    /**
     * Set service manager instance
     *
     * @param  ServiceManager $serviceManager
     * @return void
     */
    public function setServiceManager(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;
    }

    /**
     * set options
     *
     * @param  ModuleOptions $options
     * @return HybridAuth
     */
    public function setOptions(ModuleOptions $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * get options
     *
     * @return ModuleOptions
     */
    public function getOptions()
    {
        if (!$this->options instanceof ModuleOptions) {
            $this->setOptions($this->getServiceLocator()->get('adfabuser_module_options'));
        }

        return $this->options;
    }

    /**
     * @param  UserServiceOptionsInterface $options
     * @return HybridAuth
     */
    public function setZfcUserOptions(UserServiceOptionsInterface $options)
    {
        $this->zfcUserOptions = $options;

        return $this;
    }

    /**
     * @return UserServiceOptionsInterface
     */
    public function getZfcUserOptions()
    {
        if (!$this->zfcUserOptions instanceof UserServiceOptionsInterface) {
            $this->setZfcUserOptions($this->getServiceManager()->get('zfcuser_module_options'));
        }

        return $this->zfcUserOptions;
    }

    /**
     * set mapper
     *
     * @param  UserProvider $mapper
     * @return HybridAuth
     */
    public function setMapper(UserProvider $mapper)
    {
        $this->mapper = $mapper;

        return $this;
    }

    /**
     * get mapper
     *
     * @return UserProviderInterface
     */
    public function getMapper()
    {
        if (!$this->mapper instanceof UserProvider) {
            $this->setMapper($this->getServiceLocator()->get('adfabuser_userprovider_mapper'));
        }

        return $this->mapper;
    }

    /**
     * set zfcUserMapper
     *
     * @param  UserMapperInterface $zfcUserMapper
     * @return HybridAuth
     */
    public function setZfcUserMapper(UserMapperInterface $zfcUserMapper)
    {
        $this->zfcUserMapper = $zfcUserMapper;

        return $this;
    }

    /**
     * get zfcUserMapper
     *
     * @return UserMapperInterface
     */
    public function getZfcUserMapper()
    {
        if (!$this->zfcUserMapper instanceof UserMapperInterface) {
            $this->setZfcUserMapper($this->getServiceLocator()->get('zfcuser_user_mapper'));
        }

        return $this->zfcUserMapper;
    }

    /**
     * Utility function to instantiate a fresh local user object
     *
     * @return mixed
     */
    protected function instantiateLocalUser()
    {
        $userModelClass = $this->getZfcUserOptions()->getUserEntityClass();

        return new $userModelClass;
    }

    // Provider specific methods

    protected function facebookToLocalUser($userProfile)
    {
        if (!isset($userProfile->emailVerified)) {
            throw new Exception\RuntimeException(
                'Please verify your email with Facebook before attempting login',
                Result::FAILURE_CREDENTIAL_INVALID
            );
        }
        $mapper = $this->getZfcUserMapper();
        if (false != ($localUser = $mapper->findByEmail($userProfile->emailVerified))) {
            return $localUser;
        }
        $localUser = $this->instantiateLocalUser();
        $localUser->setEmail($userProfile->emailVerified)
            ->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'facebook', $userProfile);

        return $localUser;
    }

    protected function foursquareToLocalUser($userProfile)
    {
        if (!isset($userProfile->emailVerified)) {
            throw new Exception\RuntimeException(
                'Please verify your email with Foursquare before attempting login',
                Result::FAILURE_CREDENTIAL_INVALID
            );
        }
        $mapper = $this->getZfcUserMapper();
        if (false != ($localUser = $mapper->findByEmail($userProfile->emailVerified))) {
            return $localUser;
        }
        $localUser = $this->instantiateLocalUser();
        $localUser->setEmail($userProfile->emailVerified)
            ->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'foursquare', $userProfile);

        return $localUser;
    }

    protected function googleToLocalUser($userProfile)
    {
        if (!isset($userProfile->emailVerified)) {
            throw new Exception\RuntimeException(
                'Please verify your email with Google before attempting login',
                Result::FAILURE_CREDENTIAL_INVALID
            );
        }
        $mapper = $this->getZfcUserMapper();
        if (false != ($localUser = $mapper->findByEmail($userProfile->emailVerified))) {
            return $localUser;
        }
        $localUser = $this->instantiateLocalUser();
        $localUser->setEmail($userProfile->emailVerified)
            ->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'google', $userProfile);

        return $localUser;
    }

    protected function linkedInToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'linkedIn', $userProfile);

        return $localUser;
    }

    protected function twitterToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setUsername($userProfile->displayName)
            ->setDisplayName($userProfile->firstName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'twitter', $userProfile);

        return $localUser;
    }

    protected function yahooToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'yahoo', $userProfile);

        return $localUser;
    }

    protected function githubToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
                  ->setPassword(__FUNCTION__)
                  ->setEmail($userProfile->email);

        $this->getEventManager()->trigger(__FUNCTION__, $localUser, array('userProfile' => $userProfile));

        $result = $this->insert($localUser, 'github', $userProfile);

        return $localUser;
    }

    /**
     * persists the user in the db, and trigger a pre and post events for it
     * @param  mixed  $user
     * @param  string $provider
     * @param  mixed  $userProfile
     * @return mixed
     */
    protected function insert($user, $provider, $userProfile)
    {
        $zfcUserOptions = $this->getZfcUserOptions();

        // If user state is enabled, set the default state value
        if ($zfcUserOptions->getEnableUserState()) {
            if ($zfcUserOptions->getDefaultUserState()) {
                $user->setState((int) $zfcUserOptions->getDefaultUserState());
            }
        }

        $roleMapper          = $this->getRoleMapper();
        $defaultRegisterRole = $this->getOptions()->getDefaultRegisterRole();
        $role = $roleMapper->findByRoleId($defaultRegisterRole);
        $user->addRole($role);

        $options = array(
            'user'          => $user,
            'provider'      => $provider,
            'userProfile'   => $userProfile,
        );

        $this->getEventManager()->trigger('registerViaProvider', $this, $options);
        $result = $this->getZfcUserMapper()->insert($user);
        $this->getEventManager()->trigger('registerViaProvider.post', $this, $options);

        return $result;
    }

    /**
     * Set Event Manager
     *
     * @param  EventManagerInterface $events
     * @return HybridAuth
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_called_class(),
        ));
        $this->events = $events;

        return $this;
    }

    /**
     * Get Event Manager
     *
     * Lazy-loads an EventManager instance if none registered.
     *
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (null === $this->events) {
            $this->setEventManager(new EventManager());
        }

        return $this->events;
    }

    /**
     * getRoleMapper
     *
     * @return UserMapperInterface
     */
    public function getRoleMapper()
    {
    	if (null === $this->roleMapper) {
    		$this->roleMapper = $this->getServiceManager()->get('adfabuser_role_mapper');
    	}

    	return $this->roleMapper;
    }
}
