<?php

class Suin_FTPClient_FTPClient implements Suin_FTPClient_FTPClientInterface,
                                          Suin_FTPClient_ObservableInterface
{
	/** @var resource */
	protected $connection = null;
	protected $timeout = 90;
	protected $transferMode = null;

	/** @var Suin_FTPClient_ObserverInterface */
	protected $observer = null;

	/**
	 * Connect to the server and return the new FTPClientInterface object.
	 * @param string $host
	 * @param int $port
	 * @param int $transferMode
	 * @throws RuntimeException If failed to connect to the server.
	 * @throws InvalidArgumentException
	 */
	public function __construct($host, $port = 21, $transferMode = self::TRANSFER_MODE_PASSIVE)
	{
		$this->connection = fsockopen($host, $port, $errorCode, $errorMessage);

		if ( is_resource($this->connection) === false )
		{
			throw new RuntimeException($errorMessage, $errorCode);
		}

		$this->transferMode = $transferMode;

		if ( in_array($this->transferMode, array(self::TRANSFER_MODE_PASSIVE)) === false )
		{
			// TODO >> support active mode.
			throw new InvalidArgumentException('Transfer mode is invalid.');
		}

		stream_set_blocking($this->connection, true);
		stream_set_timeout($this->connection, $this->timeout);

		$response = $this->_getResponse();

		if ( $response['code'] !== 220 )
		{
			throw new RuntimeException('Failed to connect to the FTP Server.');
		}
	}

	/**
	 * Login to the server.
	 * @param string $username
	 * @param string $password
	 * @return bool If success return TRUE, fail return FALSE.
	 */
	public function login($username, $password)
	{
		$response = $this->_request(sprintf('USER %s', $username));

		if ( $response['code'] !== 331 )
		{
			return false;
		}

		$response = $this->_request(sprintf('PASS %s', $password));

		if ( $response['code'] !== 230 )
		{
			return false;
		}

		return true;
	}

	/**
	 * Close the connection.
	 * @return void
	 */
	public function disconnect()
	{
		$this->_request('QUIT');
		$this->connection = null;
	}

	/**
	 * Return the current directory name.
	 * @return string|bool If error, returns FALSE.
	 */
	public function getCurrentDirectory()
	{
		$response = $this->_request('PWD');

		if ( $response['code'] !== 257 )
		{
			return false;
		}

		$from = strpos($response['message'], '"') + 1;
		$to   = strrpos($response['message'], '"') - $from;
		$currentDirectory = substr($response['message'], $from, $to);
		return $currentDirectory;
	}

	/**
	 * Change the current directory on a FTP server.
	 * @param string $directory
	 * @return bool If success return TRUE, fail return FALSE.
	 */
	public function changeDirectory($directory)
	{
		$response = $this->_request(sprintf('CWD %s', $directory));
		return ( $response['code'] === 250 );
	}

	/**
	 * Remove a directory.
	 * @param string $directory
	 * @return bool If success return TRUE, fail return FALSE.
	 */
	public function removeDirectory($directory)
	{
		$response = $this->_request(sprintf('RMD %s', $directory));
		return ( $response['code'] === 250 );
	}

	/**
	 * Create a directory.
	 * @param string $directory
	 * @return bool If success return TRUE, fail return FALSE.
	 */
	public function createDirectory($directory)
	{
		$response = $this->_request(sprintf('MKD %s', $directory));
		return ( $response['code'] === 257 );
	}

	/**
	 * Rename a file or a directory on the FTP server.
	 * @param string $oldName
	 * @param string $newName
	 * @return bool If success return TRUE, fail return FALSE.
	 */
	public function rename($oldName, $newName)
	{
		$response = $this->_request(sprintf('RNFR %s', $oldName));

		if ( $response['code'] !== 350 )
		{
			return false;
		}

		$response = $this->_request(sprintf('RNTO %s', $newName));

		if ( $response['code'] !== 250 )
		{
			return false;
		}

		return true;
	}

	/**
	 * Delete a file on the FTP server.
	 * @param string $filename
	 * @return bool If success return TRUE, fail return FALSE.
	 */
	public function removeFile($filename)
	{
		$response = $this->_request(sprintf('DELE %s', $filename));
		return ( $response['code'] === 250 );
	}

	/**
	 * Set permissions on a file via FTP.
	 * @param string $filename
	 * @param int $mode The new permissions, given as an octal value.
	 * @return bool If success return TRUE, fail return FALSE.
	 * @throws InvalidArgumentException
	 */
	public function setPermission($filename, $mode)
	{
		if ( is_integer($mode) === false or $mode < 0 or 0777 < $mode )
		{
			throw new InvalidArgumentException(sprintf('Invalid permission "%o" was given.', $mode));
		}

		$response = $this->_request(sprintf('SITE CHMOD %o %s', $mode, $filename));
		return ( $response['code'] === 200 );
	}

	/**
	 * Return a list of files in the given directory.
	 * @param string $directory
	 * @return array|bool If error, returns FALSE.
	 */
	public function getList($directory)
	{
		$dataConnection = $this->_openPassiveDataConnection();

		if ( $dataConnection === false )
		{
			return false;
		}

		$response = $this->_request(sprintf('NLST %s', $directory));

		if ( $response['code'] !== 150 )
		{
			return false;
		}

		$list = '';

		while ( feof($dataConnection) === false )
		{
			$list .= fread($dataConnection, 1024);
		}

		$list = trim($list);
		$list = preg_split("/[\n\r]+/", $list);

		return $list;
	}

	/**
	 * Download a file from the FTP server.
	 * @param string $remoteFilename
	 * @param string $localFilename
	 * @param int $mode self::MODE_ASCII or self::MODE_BINARY
	 * @return bool If success return TRUE, fail return FALSE.
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function download($remoteFilename, $localFilename, $mode)
	{
		$modes = array(
			self::MODE_ASCII  => 'A',
			self::MODE_BINARY => 'I',
		);

		if ( array_key_exists($mode, $modes) === false )
		{
			throw new InvalidArgumentException(sprintf('Invalid mode "%s" was given', $mode));
		}

		/*
		 * WHY USE 'wb' HERE?
		 * As fopen() function modifies line break character like LF, CR, CRLF depending on SAPI,
		 * we use 'b' here in order to receive data as plain.
		 * @see http://www.php.net/manual/en/function.fopen.php
		 */
		$localFilePointer = fopen($localFilename, 'wb');

		if ( is_resource($localFilePointer) === false )
		{
			throw new RuntimeException(sprintf('Failed to open local file "%s"', $localFilename));
		}

		$response = $this->_request(sprintf('TYPE %s', $modes[$mode]));

		if ( $response['code'] !== 200 )
		{
			return false;
		}

		$dataConnection = $this->_openPassiveDataConnection();

		if ( $dataConnection === false )
		{
			return false;
		}

		$response = $this->_request(sprintf('RETR %s', $remoteFilename));

		if ( $response['code'] !== 150 )
		{
			return false;
		}

		while ( feof($dataConnection) === false )
		{
			fwrite($localFilePointer, fread($dataConnection, 10240), 10240);
		}


		return true;
	}

	/**
	 * Upload a file to the FTP server.
	 * @param string $localFilename
	 * @param string $remoteFilename
	 * @param int $mode self::MODE_ASCII or self::MODE_BINARY
	 * @return bool If success return TRUE, fail return FALSE.
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function upload($localFilename, $remoteFilename, $mode)
	{
		$modes = array(
			self::MODE_ASCII  => 'A',
			self::MODE_BINARY => 'I',
		);

		if ( array_key_exists($mode, $modes) === false )
		{
			throw new InvalidArgumentException(sprintf('Invalid mode "%s" was given', $mode));
		}

		/*
		 * WHY USE 'wb' HERE?
		 * As fopen() function modifies line break character like LF, CR, CRLF depending on SAPI,
		 * we use 'b' here in order to receive data as plain.
		 * @see http://www.php.net/manual/en/function.fopen.php
		 */
		$localFilePointer = fopen($localFilename, 'rb');

		if ( is_resource($localFilePointer) === false )
		{
			throw new RuntimeException(sprintf('Failed to open local file "%s"', $localFilename));
		}

		$response = $this->_request(sprintf('TYPE %s', $modes[$mode]));

		if ( $response['code'] !== 200 )
		{
			return false;
		}

		$dataConnection = $this->_openPassiveDataConnection();

		if ( $dataConnection === false )
		{
			return false;
		}

		$response = $this->_request(sprintf('STOR %s', $remoteFilename));

		if ( $response['code'] !== 150 )
		{
			return false;
		}

		while ( feof($localFilePointer) === false )
		{
			fwrite($dataConnection, fread($localFilePointer, 10240), 10240);
		}

		return true;
	}

	/**
	 * Set an observer.
	 * @param Suin_FTPClient_ObserverInterface $observer
	 */
	public function setObserver(Suin_FTPClient_ObserverInterface $observer)
	{
		$this->observer = $observer;
	}

	/**
	 * Open new passive data connection.
	 * @return resource|bool
	 */
	protected function _openPassiveDataConnection()
	{
		$response = $this->_request('PASV');

		if ( $response['code'] !== 227 )
		{
			return false;
		}

		if ( !preg_match('/\((?P<host>[0-9,]+),(?P<port1>[0-9]+),(?P<port2>[0-9]+)\)/', $response['message'], $matches) )
		{
			return false;
		}

		$host = strtr($matches['host'], ',', '.');
		$port = ( $matches['port1'] * 256 ) + $matches['port2']; // low bit * 256 + high bit

		$dataConnection = fsockopen($host, $port, $errorNumber, $errorString, $this->timeout);

		if ( is_resource($dataConnection) === false )
		{
			return false;
		}

		stream_set_blocking($dataConnection, true);
		stream_set_timeout($dataConnection, $this->timeout);

		return $dataConnection;
	}

	/**
	 * Send a request.
	 * @param string $request
	 * @return array
	 */
	protected function _request($request)
	{
		$request = $request."\r\n";

		if ( is_object($this->observer) === true )
		{
			$this->observer->updateWithRequest($request);
		}

		fputs($this->connection, $request);
		return $this->_getResponse();
	}

	/**
	 * Fetch the response.
	 * @return array
	 */
	protected function _getResponse()
	{
		$response = array(
			'code'    => 0,
			'message' => '',
		);

		do
		{
			$response['message'] = fgets($this->connection, 8129);
			$array = stream_get_meta_data($this->connection);
		} while ( $array['unread_bytes'] > 0 );

		$response['code'] = intval(substr(ltrim($response['message']), 0, 3));

		if ( is_object($this->observer) === true )
		{
			$this->observer->updateWithResponse($response['message'], $response['code']);
		}

		return $response;
	}
}