<?php
/**
 * @package    Fuel\Upload
 * @version    2.0
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2015 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Fuel\Upload;

/**
 * Files is a container for a single uploaded file
 */
class File implements \ArrayAccess, \Iterator, \Countable
{
	/**
	 * Our custom error code constants
	 */
	const UPLOAD_ERR_MAX_SIZE             = 101;
	const UPLOAD_ERR_EXT_BLACKLISTED      = 102;
	const UPLOAD_ERR_EXT_NOT_WHITELISTED  = 103;
	const UPLOAD_ERR_TYPE_BLACKLISTED     = 104;
	const UPLOAD_ERR_TYPE_NOT_WHITELISTED = 105;
	const UPLOAD_ERR_MIME_BLACKLISTED     = 106;
	const UPLOAD_ERR_MIME_NOT_WHITELISTED = 107;
	const UPLOAD_ERR_MAX_FILENAME_LENGTH  = 108;
	const UPLOAD_ERR_MOVE_FAILED          = 109;
	const UPLOAD_ERR_DUPLICATE_FILE       = 110;
	const UPLOAD_ERR_MKDIR_FAILED         = 111;
	const UPLOAD_ERR_EXTERNAL_MOVE_FAILED = 112;
	const UPLOAD_ERR_NO_PATH              = 113;

	/**
	 * @var array
	 */
	protected $container = array();

	/**
	 * @var integer
	 */
	protected $index = 0;

	/**
	 * @var array
	 */
	protected $errors = array();

	/**
	 * @var array
	 */
	protected $config = array(
		'langCallback'    => null,
		'moveCallback'    => null,
		// validation settings
		'max_size'        => 0,
		'max_length'      => 0,
		'ext_whitelist'   => array(),
		'ext_blacklist'   => array(),
		'type_whitelist'  => array(),
		'type_blacklist'  => array(),
		'mime_whitelist'  => array(),
		'mime_blacklist'  => array(),
		// file settings
		'prefix'          => '',
		'suffix'          => '',
		'extension'       => '',
		'randomize'       => false,
		'normalize'       => false,
		'normalize_separator' => '_',
		'change_case'     => false,
		// save-to-disk settings
		'path'            => '',
		'create_path'     => true,
		'path_chmod'      => 0777,
		'file_chmod'      => 0666,
		'auto_rename'     => true,
		'new_name'        => false,
		'overwrite'       => false,
	);

	/**
	 * @var boolean
	 */
	protected $isValidated = false;

	/**
	 * @var boolean
	 */
	protected $isValid = false;

	/**
	 * @var array
	 */
	protected $callbacks = array();

	/**
	 * @param array       $file
	 * @param array|null  $callbacks
	 */
	public function __construct(array $file, &$callbacks = array())
	{
		// store the file data for this file
		$this->container = $file;

		// the file callbacks reference
		$this->callbacks =& $callbacks;
	}

	/**
	 * Magic getter, gives read access to all elements in the file container
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function __get($name)
	{
		$name = strtolower($name);
		return isset($this->container[$name]) ? $this->container[$name] : null;
	}

	/**
	 * Magic setter, gives write access to all elements in the file container
	 *
	 * @param string $name
	 * @param mixed  $value
	 */
	public function __set($name, $value)
	{
		$name = strtolower($name);
		array_key_exists($name, $this->container) and $this->container[$name] = $value;
	}

	/**
	 * Returns the validation state of this object
	 *
	 * @return boolean
	 */
	public function isValidated()
	{
		return $this->isValidated;
	}

	/**
	 * Returns the state of this object
	 *
	 * @return  boolean
	 */
	public function isValid()
	{
		return $this->isValid;
	}

	/**
	 * Returns the error objects collected for this file upload
	 *
	 * @return  FileError[]
	 */
	public function getErrors()
	{
		return $this->isValidated ? $this->errors : array();
	}

	/**
	 * Sets the configuration for this file
	 *
	 * @param string|array  $item
	 * @param mixed         $value
	 */
	public function setConfig($item, $value = null)
	{
		// unify the parameters
		is_array($item) or $item = array($item => $value);

		// update the configuration
		foreach ($item as $name => $value)
		{
			array_key_exists($name, $this->config) and $this->config[$name] = $value;
		}
	}

	/**
	 * Runs validation on the uploaded file, based on the config being loaded
	 *
	 * @return boolean
	 */
	public function validate()
	{
		// reset the error container and status
		$this->errors = array();
		$this->isValid = true;

		// validation starts, call the pre-validation callback
		$this->runCallbacks('before_validation');

		// was the upload of the file a success?
		if ($this->container['error'] == 0)
		{
			// add some filename details (pathinfo can't be trusted with utf-8 filenames!)
			$this->container['extension'] = ltrim(strrchr(ltrim($this->container['name'], '.'), '.'),'.');
			if (empty($this->container['extension']))
			{
				$this->container['basename'] = $this->container['name'];
			}
			else
			{
				$this->container['basename'] = substr($this->container['name'], 0, strlen($this->container['name'])-(strlen($this->container['extension'])+1));
			}

			// does this upload exceed the maximum size?
			if ( ! empty($this->config['max_size']) and is_numeric($this->config['max_size']) and $this->container['size'] > $this->config['max_size'])
			{
				$this->addError(static::UPLOAD_ERR_MAX_SIZE);
			}

			// add mimetype information
			try
			{
				$handle = finfo_open(FILEINFO_MIME_TYPE);
				$this->container['mimetype'] = finfo_file($handle, $this->container['tmp_name']);
				finfo_close($handle);
			}
			// this will only work if PHP errors are converted into ErrorException (like when you use FuelPHP)
			catch (\ErrorException $e)
			{
				$this->container['mimetype'] = false;
				$this->addError(UPLOAD_ERR_NO_FILE);
			}

			// make sure it contains something valid
			if (empty($this->container['mimetype']) or strpos($this->container['mimetype'], '/') === false)
			{
				$this->container['mimetype'] = 'application/octet-stream';
			}

			// split the mimetype info so we can run some tests
			preg_match('|^(.*)/(.*)|', $this->container['mimetype'], $mimeinfo);

			// check the file extension black- and whitelists
			if (in_array(strtolower($this->container['extension']), (array) $this->config['ext_blacklist']))
			{
				$this->addError(static::UPLOAD_ERR_EXT_BLACKLISTED);
			}
			elseif ( ! empty($this->config['ext_whitelist']) and ! in_array(strtolower($this->container['extension']), (array) $this->config['ext_whitelist']))
			{
				$this->addError(static::UPLOAD_ERR_EXT_NOT_WHITELISTED);
			}

			// check the file type black- and whitelists
			if (in_array($mimeinfo[1], (array) $this->config['type_blacklist']))
			{
				$this->addError(static::UPLOAD_ERR_TYPE_BLACKLISTED);
			}
			if ( ! empty($this->config['type_whitelist']) and ! in_array($mimeinfo[1], (array) $this->config['type_whitelist']))
			{
				$this->addError(static::UPLOAD_ERR_TYPE_NOT_WHITELISTED);
			}

			// check the file mimetype black- and whitelists
			if (in_array($this->container['mimetype'], (array) $this->config['mime_blacklist']))
			{
				$this->addError(static::UPLOAD_ERR_MIME_BLACKLISTED);
			}
			elseif ( ! empty($this->config['mime_whitelist']) and ! in_array($this->container['mimetype'], (array) $this->config['mime_whitelist']))
			{
				$this->addError(static::UPLOAD_ERR_MIME_NOT_WHITELISTED);
			}

			// validation finished, call the post-validation callback
			$this->runCallbacks('after_validation');
		}
		else
		{
			// upload was already a failure, store the corresponding error
			$this->addError($this->container['error']);
		}

		// set the flag to indicate we ran the validation
		$this->isValidated = true;

		// return the validation state
		return $this->isValid;
	}

	/**
	 * Saves the uploaded file
	 *
	 * @return boolean
	 *
	 * @throws \DomainException if destination path specified does not exist
	 */
	public function save()
	{
		$tempfileCreated = false;

		// we can only save files marked as valid
		if ($this->isValid)
		{
			// make sure we have a valid path
			if (empty($this->container['path']))
			{
				$this->container['path'] = rtrim($this->config['path'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
			}

			// if the path does not exist
			if ( ! is_dir($this->container['path']))
			{
				// do we need to create it?
				if ((bool) $this->config['create_path'])
				{
					@mkdir($this->container['path'], $this->config['path_chmod'], true);

					if ( ! is_dir($this->container['path']))
					{
						$this->addError(static::UPLOAD_ERR_MKDIR_FAILED);
					}
				}
				else
				{
					$this->addError(static::UPLOAD_ERR_NO_PATH);
				}
			}

			// start processing the uploaded file
			if ($this->isValid)
			{
				$this->container['path'] = realpath($this->container['path']).DIRECTORY_SEPARATOR;

				// was a new name for the file given?
				if ( ! is_string($this->container['filename']) or $this->container['filename'] === '')
				{
					// do we need to generate a random filename?
					if ( (bool) $this->config['randomize'])
					{
						$this->container['filename'] = md5(serialize($this->container));
					}

					// do we need to normalize the filename?
					else
					{
						$this->container['filename']  = $this->container['basename'];
						(bool) $this->config['normalize'] and $this->normalize();
					}
				}

				// was a hardcoded new name specified in the config?
				if (array_key_exists('new_name', $this->config) and $this->config['new_name'] !== false)
				{
					$new_name = pathinfo($this->config['new_name']);
					empty($new_name['filename']) or $this->container['filename'] = $new_name['filename'];
					empty($new_name['extension']) or $this->container['extension'] = $new_name['extension'];
				}

				// array with all filename components
				$filename = array(
					$this->config['prefix'],
					$this->container['filename'],
					$this->config['suffix'],
					'',
					'.',
					empty($this->config['extension']) ? $this->container['extension'] : $this->config['extension']
				);

				// remove the dot if no extension is present
				empty($filename[5]) and $filename[4] = '';

				// need to modify case?
				switch($this->config['change_case'])
				{
					case 'upper':
						$filename = array_map(function($var) { return strtoupper($var); }, $filename);
					break;

					case 'lower':
						$filename = array_map(function($var) { return strtolower($var); }, $filename);
					break;

					default:
					break;
				}

				// if we're saving the file locally
				if ( ! $this->config['moveCallback'])
				{
					// check if the file already exists
					if (file_exists($this->container['path'].implode('', $filename)))
					{
						// generate a unique filename if needed
						if ( (bool) $this->config['auto_rename'])
						{
							$counter = 0;
							do
							{
								$filename[3] = '_'.++$counter;
							}
							while (file_exists($this->container['path'].implode('', $filename)));

							// claim this generated filename before someone else does
							touch($this->container['path'].implode('', $filename));
							$tempfileCreated = true;
						}
						else
						{
							// if we can't overwrite, we've got to bail out now
							if ( ! (bool) $this->config['overwrite'])
							{
								$this->addError(static::UPLOAD_ERR_DUPLICATE_FILE);
							}
						}
					}
				}

				// no need to store it as an array anymore
				$this->container['filename'] = implode('', $filename);

				// does the filename exceed the maximum length?
				if ( ! empty($this->config['max_length']) and strlen($this->container['filename']) > $this->config['max_length'])
				{
					$this->addError(static::UPLOAD_ERR_MAX_FILENAME_LENGTH);
				}

				// if the file is still valid, run the before save callbacks
				if ($this->isValid)
				{
					// validation starts, call the pre-save callbacks
					$this->runCallbacks('before_save');

					// recheck the path, it might have been altered by a callback
					if ($this->isValid and ! is_dir($this->container['path']) and (bool) $this->config['create_path'])
					{
						@mkdir($this->container['path'], $this->config['path_chmod'], true);

						if ( ! is_dir($this->container['path']))
						{
							$this->addError(static::UPLOAD_ERR_MKDIR_FAILED);
						}
					}

					// if the file is still valid, move it
					if ($this->isValid)
					{
						// check if file should be moved to an ftp server
						if ($this->config['moveCallback'])
						{
							$moved = call_user_func($this->config['moveCallback'], $this->container['tmp_name'], $this->container['path'].$this->container['filename']);

							if ( ! $moved)
							{
								$this->addError(static::UPLOAD_ERR_EXTERNAL_MOVE_FAILED);
							}
						}
						else
						{
							if( ! @move_uploaded_file($this->container['tmp_name'], $this->container['path'].$this->container['filename']))
							{
								$this->addError(static::UPLOAD_ERR_MOVE_FAILED);
							}
							else
							{
								@chmod($this->container['path'].$this->container['filename'], $this->config['file_chmod']);
							}
						}
					}
				}
			}

			// call the post-save callbacks if the file was succefully saved
			if ($this->isValid)
			{
				$this->runCallbacks('after_save');
			}

			// if there was an error and we've created a temp file, make sure to remove it
			elseif ($tempfileCreated)
			{
				unlink($this->container['path'].$this->container['filename']);
			}
		}

		// return the status of this operation
		return $this->isValid;
	}

	/**
	 * Runs callbacks of he defined type
	 *
	 * @param callable $type
	 */
	protected function runCallbacks($type)
	{
		// make sure we have callbacks of this type
		if (array_key_exists($type, $this->callbacks))
		{
			// run the defined callbacks
			foreach ($this->callbacks[$type] as $callback)
			{
				// check if the callback is valid
				if (is_callable($callback))
				{
					// call the defined callback
					$result = call_user_func_array($callback, array(&$this));

					// and process the results. we need FileError instances only
					foreach ((array) $result as $entry)
					{
						if (is_object($entry) and $entry instanceOf FileError)
						{
							$this->errors[] = $entry;
						}
					}

					// update the status of this validation
					$this->isValid = empty($this->errors);
				}
			}
		}
	}

	/**
	 * Converts a filename into a normalized name. only outputs 7 bit ASCII characters.
	 */
	protected function normalize()
	{
		// Decode all entities to their simpler forms
		$this->container['filename'] = html_entity_decode($this->container['filename'], ENT_QUOTES, 'UTF-8');

		// Remove all quotes
		$this->container['filename'] = preg_replace("#[\"\']#", '', $this->container['filename']);

		// Strip unwanted characters
		$this->container['filename'] = preg_replace("#[^a-z0-9]#i", $this->config['normalize_separator'], $this->container['filename']);
		$this->container['filename'] = preg_replace("#[/_|+ -]+#u", $this->config['normalize_separator'], $this->container['filename']);
		$this->container['filename'] = trim($this->container['filename'], $this->config['normalize_separator']);
	}

	/**
	 * Adds a new error object to the list
	 *
	 * @param integer $error
	 */
	protected function addError($error)
	{
		$this->errors[] = new FileError($error, $this->config['langCallback']);
		$this->isValid = false;
	}

	//------------------------------------------------------------------------------------------------------------------

	/**
	 * Countable methods
	 */
	public function count()
	{
		return count($this->container);
	}

	/**
	 * ArrayAccess methods
	 */
	public function offsetExists($offset)
	{
		return isset($this->container[$offset]);
	}

	public function offsetGet($offset)
	{
		return $this->container[$offset];
	}

	public function offsetSet($offset, $value)
	{
		$this->container[$offset] = $value;
	}

	public function offsetUnset($offset)
	{
		throw new \OutOfBoundsException('You can not unset a data element of an Upload File instance');
	}

	/**
	 * Iterator methods
	 */
	function rewind()
	{
		return reset($this->container);
	}

	function current()
	{
		return current($this->container);
	}

	function key()
	{
		return key($this->container);
	}

	function next()
	{
		return next($this->container);
	}

	function valid()
	{
		return key($this->container) !== null;
	}
}
