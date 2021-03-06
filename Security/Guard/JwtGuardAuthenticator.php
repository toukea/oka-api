<?php
namespace Oka\ApiBundle\Security\Guard;

use Aynid\UserBundle\Security\UserProvider;
use Oka\ApiBundle\Service\ErrorResponseFactory;
use Oka\ApiBundle\Util\JSONWebTokenHelper;
use Oka\ApiBundle\Util\RequestUtil;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

/**
 * 
 * @author Cedrick Oka Baidai <okacedrick@gmail.com>
 * 
 * @deprecated 1.2.0 Not using
 */
class JwtGuardAuthenticator extends AbstractGuardAuthenticator
{
	/**
	 * @var JSONWebTokenHelper $jwtHelper
	 */
	private $jwtHelper;
	
	/**
	 * @var ErrorResponseFactory $errorFactory
	 */
	protected $errorFactory;
	
	/**
	 * @var string $userClass
	 */
	private $userClass;
	
	public function __construct(JSONWebTokenHelper $jwtHelper, ErrorResponseFactory $errorFactory, $userClass)
	{
		$this->jwtHelper = $jwtHelper;
		$this->errorFactory = $errorFactory;
		$this->userClass = $userClass;
	}
	
	public function getCredentials(Request $request)
	{
		$matches = [];
		
		if ($request->headers->has('Authorization') && preg_match('#Bearer (.+)$#', $request->headers->get('Authorization'), $matches)) {			
			/** @var \Lcobucci\JWT\Token $jwtToken */
			$jwtToken = $this->jwtHelper->parse($matches[1]);
			
			if ($jwtToken->hasClaim('jti') && !$jwtToken->isExpired()) {								
				return ['username' => $jwtToken->getClaim('jti'), 'token' => $jwtToken];
			}
		}
	}
	
	public function getUser($credentials, UserProviderInterface $userProvider)
	{
		$user = $userProvider->loadUserByUsername($credentials['username']);
		
		if (!$user instanceof $this->userClass) {
			throw new AuthenticationServiceException(sprintf('Authentication request could not be processed, user loaded by provider "%s" is not instance of class "%s".', get_class($userProvider), $this->userClass));
		}
		
		if (!method_exists($user, $this->authMethodName)) {
			throw new AuthenticationServiceException(sprintf('Authentication request could not be processed, user loaded by provider "%s" doesn\'t contains method "%s".', get_class($userProvider), $this->authMethodName));
		}
		
		return $user;
	}
	
	public function checkCredentials($credentials, UserInterface $user)
	{
		return $this->jwtHelper->verify($user, $credentials['token']);
	}
	
	public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
	{
		return null;
	}
	
	public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
	{
		return $this->errorFactory->create($exception->getMessage(), Response::HTTP_FORBIDDEN, null, [], Response::HTTP_FORBIDDEN, ['X-Secure-With' => 'JWT'], RequestUtil::getFirstAcceptableFormat($request) ?: 'json');
	}
	
	public function start(Request $request, AuthenticationException $authException = null)
	{
		return $this->errorFactory->create('Authentication Required.', Response::HTTP_UNAUTHORIZED, null, [], Response::HTTP_UNAUTHORIZED, ['X-Secure-With' => 'JWT'], RequestUtil::getFirstAcceptableFormat($request) ?: 'json');
	}
	
	public function supportsRememberMe()
	{
		return false;
	}
}
