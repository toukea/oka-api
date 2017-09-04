<?php
namespace Oka\ApiBundle\Model;

/**
 * 
 * @author Cedrick Oka Baidai <okacedrick@gmail.com>
 * 
 */
interface RequestAuditInterface
{
	public function getId();
	
	public function getUser();
	
	public function getMethod();
	
	public function getUrl();
	
	public function getPathInfo();
	
	public function getIp();
	
	public function getHost();
	
	public function getQueryParameters();
	
	public function getRequestParameters();
	
	public function getStatusCode();
	
	public function getStatusText();
	
	public function getAuditedAt();
}