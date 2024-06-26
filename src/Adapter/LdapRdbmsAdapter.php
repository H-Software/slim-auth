<?php
/**
 * Slim Auth.
 *
 * @link      http://github.com/marcelbonnet/slim-auth
 *
 * @copyright Copyright (c) 2016 Marcel Bonnet (http://github.com/marcelbonnet)
 * @license   MIT
 */
namespace czhujer\Slim\Auth\Adapter;

use Laminas\Authentication\Adapter\AbstractAdapter;
use Laminas\Authentication\Result as AuthenticationResult;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use czhujer\Slim\Auth\Events\SlimAuthEventInterface;

/**
 * Authenticate through LDAP
 * Authorize through RDBMS, using Doctrine ORM
 * @author marcelbonnet
 * @since 0.0.2
 */
class LdapRdbmsAdapter extends AbstractAdapter
{
	
	/**
	 * Used when authenticate through LDAP/AD
	 * when connected through intranet or VPN
	 * @var integer
	 */
	const AUTHENTICATE_LDAP = 1;
	
	/**
	 * Used when LDAP/AD is not available (like
	 * for users connected through the internet,
	 * thus authenticates using RDBMS
	 * @var integer
	 */
	const AUTHENTICATE_RDBMS = 2;
	
    protected static $configFile 		= null;
    
    protected $entityManager 			= null;
    protected $roleEntity 				= null;
    protected $roleAttribute 			= null;
    protected $roleAssociationAttribute = null;
    protected $roleUserAssociationAttribute = null;
    protected $identityAttribute 		= null;
    protected $userEntity 				= null;
    protected $credencialAttribute		= null;
    protected $authType 				= null;
    protected $pwdHashFactor			= null;
    protected $pwdHashAlgo			= null;
    protected $options				= null;
    
    /**
     * @var \czhujer\Slim\Auth\Events\SlimAuthEventInterface
     */
    protected $authenticationEvent	= null;
    /**
     * @var \czhujer\Slim\Auth\Events\SlimAuthorizationEventInterface
     */
    protected $authorizationEvent	= null;

    /**
     * As we this Adapter uses Doctrine ORM, we suppose
     * that the User and Roles model both have an atrribute nammed
     * <em>id</em> annotated with
     * <pre> 
     * @Id 
	 * @GeneratedValue 
	 * @Column(type="integer") 
	 * protected $id;
     * </pre>
     * @param string $configIniFile ini file with LDAP settings
     * @param EntityManager $entityManager
     * @param string $roleFQCN Role Entity fully qualified class name
     * @param string $roleAttribute attribute of $roleFQCN holding roles
     * @param string $roleUserAssociationAttribute attribute of $roleFQCN holding its user object
     * @param string $userEntity User Entity FQCN (fully qualified class name)
     * @param string $identityAttribute atrribute of $userEntity holding username
     * @param string $credencialAttribute atrribute of $userEntity holding password
     * @param integer $authType one of AUTHENTICATE_LDAP|AUTHENTICATE_RDBMS
     * @param number $pwdHashFactor if using AUTHENTICATE_RDBMS, than sets the password hash factor
     * @param string $pwdHashAlgo if using AUTHENTICATE_RDBMS, than sets the password algorithm
     * @param array $options 
	     <pre>
	     	If authentication should check if user is activated (account is valid, whatever):
	     	[
					'checkUserIsActivated'	=> 'my_column_name', //user's column to check if user is activated
					'userIsActivatedFlag'		=> true //what value is expected if user is activated. Otherwise, authentication will fail
			]
	     </pre>
     */
    public function __construct(
    	$configIniFile,
        EntityManager $entityManager,
    	$roleFQCN = null,
    	$roleAttribute = null,
    	$roleUserAssociationAttribute = null,
    	$userEntity,
    	$identityAttribute = NULL,
    	$credencialAttribute = null,
    	$authType = self::AUTHENTICATE_LDAP,
    	$pwdHashFactor = 10,
    	$pwdHashAlgo = PASSWORD_DEFAULT,
    	$options,
    	$authenticationEvent = null,
    	$authorizationEvent = null
    ) {
    	self::$configFile		= $configIniFile;
    	$this->entityManager 	= $entityManager;
    	$this->roleEntity		= $roleFQCN;
    	$this->roleAttribute	= $roleAttribute;
    	$this->userEntity		= $userEntity;
    	$this->roleUserAssociationAttribute = $roleUserAssociationAttribute;
    	$this->identityAttribute= $identityAttribute;
    	$this->credencialAttribute= $credencialAttribute;
    	$this->authType			= $authType;
    	$this->pwdHashFactor	= $pwdHashFactor;
    	$this->pwdHashAlgo		= $pwdHashAlgo;
    	$this->options			= $options;
    	$this->authenticationEvent = $authenticationEvent;
    	$this->authorizationEvent = $authorizationEvent;
    }

    /**
     * Performs authentication.
     *
     * @return AuthenticationResult Authentication result
     */
    public function authenticate()
    {
    	$result = null;
    	if ($this->authType == self::AUTHENTICATE_LDAP){
    		$result = $this->authenticateLdap();
    	}
    	
    	if ($this->authType == self::AUTHENTICATE_RDBMS){
    		$result = $this->authenticateRdbms();
    	}
    	
    	
    	if (!$result->isValid()){
    		if($this->authenticationEvent !== null){
    			$this->authenticationEvent->onFail($result->getIdentity(), $result->getMessages());
    		}
    		
    		return new AuthenticationResult(AuthenticationResult::FAILURE
    				, $result->getIdentity()
    				, $result->getMessages());
    	}
    	
    	$userRoles = $this->findUserRoles();
    	
    	$user = array(
    			"username" 	=> $this->getIdentity(),
    			"role"		=> $userRoles,
    			"onLoginObject"	=> null
    	);
    	
    	if($this->authenticationEvent !== null){
    		$obj = $this->authenticationEvent->onLogin($this->getIdentity(), $userRoles);
    		$user['onLoginObject'] = $obj;
    	}
    	
    	return new AuthenticationResult(AuthenticationResult::SUCCESS, $user, array());
    }
    
    /**
     * Expects ini file's schema:
     * <pre>
     * [ldapauth]
     * ldap.[options]...
     * ldap.[options]...
     * ldap.[options]...
     * ...
     * </pre>
     */
    private function authenticateLdap()
    {
    	$configReader = new \Laminas\Config\Reader\Ini();
    	$configData = $configReader->fromFile(self::$configFile);
    	$config = new \Laminas\Config\Config($configData, false);
    	$options = $config->ldapauth->ldap->toArray();
    	$adapter = new \Laminas\Authentication\Adapter\Ldap($options);
    	$adapter->setIdentity($this->getIdentity());
    	$adapter->setCredential($this->getCredential());
    	return $adapter->authenticate();
    }
    
    /**
     * password_hash("teste",PASSWORD_DEFAULT, [ "cost" => 15 ])
     * 
     * @throws Exception
     * @return \Laminas\Authentication\Result
     */
    private function authenticateRdbms()
    {
    	try {
    		$user = $this->findUser($this->getIdentity());
    		
    		if(empty($user) ||
    				!password_verify($this->getCredential(), $user[$this->credencialAttribute])){
    			return new AuthenticationResult(AuthenticationResult::FAILURE_CREDENTIAL_INVALID,
    					array(),
    					array('Invalid username and/or password provided'));
    		}
    		
    		/*
    		 * Optional auth test: is user account activated ?
    		 */
    		if( $this->hasOption('checkUserIsActivated') &&
    			$this->hasOption('userIsActivatedFlag') &&
    			$user[$this->options['checkUserIsActivated']] !== $user[$this->options['userIsActivatedFlag']]
    			){
    			return new AuthenticationResult(AuthenticationResult::FAILURE,
    					array(),
    					array('User is not activated.'));
    		}
    		
    		$currentHashAlgorithm   =  $this->pwdHashAlgo;
    		$currentHashOptions  =  array('cost'   => $this->pwdHashFactor ); 
    		$passwordNeedsRehash =  password_needs_rehash(
    				$user[$this->credencialAttribute],
    				$currentHashAlgorithm,
    				$currentHashOptions
    				);
    		
    		//FIXME: must rehash if needede
    		if($passwordNeedsRehash === true){
    			//try $em findby id , set e persist
    		}
    		
    		unset($user[$this->credencialAttribute]);
    		return new AuthenticationResult(AuthenticationResult::SUCCESS, $user, array('Authenticated through RDBMS'));
    		
    	} catch (Exception $e) {
    		throw $e;
    	}
    }
    
    /**
     * Finds a user by $username
     * @param string $username
     * @return an array with id, username and password (hashed)
     * @throws Exception
     */
    private function findUser($username)
    {
    	$dql = sprintf("SELECT u.id, u.%s, u.%s
    			FROM %s u
    			WHERE u.%s = :username",
    			$this->identityAttribute,
    			$this->credencialAttribute,
    			$this->userEntity,
    			$this->identityAttribute
    			);
    	
    	if($this->hasOption('checkUserIsActivated')){
    		$dql = sprintf("SELECT u.id, u.%s, u.%s, u.%s
    			FROM %s u
    			WHERE u.%s = :username",
    				$this->identityAttribute,
    				$this->credencialAttribute,
    				$this->options['checkUserIsActivated'],
    				$this->userEntity,
    				$this->identityAttribute
    				);
    	}
    	 
    	try {
    		$query = $this->entityManager->createQuery($dql);
    		$query->setParameter("username", $this->getIdentity());
    		return $query->getSingleResult(Query::HYDRATE_ARRAY);
    	} catch (\Doctrine\ORM\NoResultException $e) {
    		return [];
    	} catch (Exception $e) {
    		throw $e;
    	}
    }
    

    /**
     * Perform a search of user's roles.
     * @return array of roles
     */
    private function findUserRoles()
    {
    	$dql = sprintf("SELECT r.%s 
    			FROM %s r
    			JOIN %s u WITH u.id = r.%s
    			WHERE u.%s = :username",
    			$this->roleAttribute,
    			$this->roleEntity,
    			$this->userEntity,
    			$this->roleUserAssociationAttribute,
    			$this->identityAttribute
    			);
    	
    	try {
    		$query = $this->entityManager->createQuery($dql);
    		$query->setParameter("username", $this->getIdentity());
    		return $query->getResult(Query::HYDRATE_ARRAY);
    	} catch (Exception $e) {
    		throw $e;
    	}
    }
    
    /**
     * @param string $opt
     * return boolean
     */
    private function hasOption($opt){
    	return (!empty($this->options) 
    			&& is_array($this->options)
    			&& array_key_exists($opt, $this->options)
//     			&& is_string($this->options[$opt])
    			);
    }

    /**
     * Get tableName.
     *
     * @return string tableName
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * Get identityColumn.
     *
     * @return string identityColumn
     */
    public function getIdentityColumn()
    {
        return $this->identityColumn;
    }

    /**
     * Get credentialColumn.
     *
     * @return string credentialColumn
     */
    public function getCredentialColumn()
    {
        return $this->credentialColumn;
    }
}
