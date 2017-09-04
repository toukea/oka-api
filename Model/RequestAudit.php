<?php
namespace Oka\ApiBundle\Model;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * 
 * @author Cedrick Oka Baidai <okacedrick@gmail.com>
 * 
 * @ODM\MappedSuperclass
 */
abstract class RequestAudit implements RequestAuditInterface
{
	/**
	 * @var mixed $id
	 */
	protected $id;
	
	/**
	 * @ODM\Field(type="string")
	 * @var string $username
	 */
	protected $user;
}