<?php
/**
 * @package    Fuel\Upload
 * @version    2.0
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2015 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Fuel\Upload\Providers;

use League\Container\ServiceProvider;
use Fuel\Upload\Upload;


/**
 * Fuel ServiceProvider class for Upload
 */
class FuelServiceProvider extends ServiceProvider
{
	/**
	 * @var array
	 */
	protected $provides = array('upload');

	/**
	 * {@inheritdoc}
	 */
	public function register()
	{
		$this->register('upload', function (array $config = null)
		{
			return new Upload($config);
		});

	}
}
