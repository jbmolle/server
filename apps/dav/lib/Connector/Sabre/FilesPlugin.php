<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Bjoern Schiessle <bjoern@schiessle.org>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Michael Jobst <mjobst+github@tecratech.de>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Tobias Kaminsky <tobias@kaminsky.me>
 * @author Vincent Petry <vincent@nextcloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\Connector\Sabre;

use OC\AppFramework\Http\Request;
use OC\Metadata\IMetadataManager;
use OCP\Constants;
use OCP\Files\ForbiddenException;
use OCP\Files\StorageNotAvailableException;
use OCP\IConfig;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\IFile;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class FilesPlugin extends ServerPlugin {
	// namespace
	public const NS_OWNCLOUD = 'http://owncloud.org/ns';
	public const NS_NEXTCLOUD = 'http://nextcloud.org/ns';
	public const FILEID_PROPERTYNAME = '{http://owncloud.org/ns}id';
	public const INTERNAL_FILEID_PROPERTYNAME = '{http://owncloud.org/ns}fileid';
	public const PERMISSIONS_PROPERTYNAME = '{http://owncloud.org/ns}permissions';
	public const SHARE_PERMISSIONS_PROPERTYNAME = '{http://open-collaboration-services.org/ns}share-permissions';
	public const OCM_SHARE_PERMISSIONS_PROPERTYNAME = '{http://open-cloud-mesh.org/ns}share-permissions';
	public const SHARE_ATTRIBUTES_PROPERTYNAME = '{http://nextcloud.org/ns}share-attributes';
	public const DOWNLOADURL_PROPERTYNAME = '{http://owncloud.org/ns}downloadURL';
	public const SIZE_PROPERTYNAME = '{http://owncloud.org/ns}size';
	public const GETETAG_PROPERTYNAME = '{DAV:}getetag';
	public const LASTMODIFIED_PROPERTYNAME = '{DAV:}lastmodified';
	public const CREATIONDATE_PROPERTYNAME = '{DAV:}creationdate';
	public const DISPLAYNAME_PROPERTYNAME = '{DAV:}displayname';
	public const OWNER_ID_PROPERTYNAME = '{http://owncloud.org/ns}owner-id';
	public const OWNER_DISPLAY_NAME_PROPERTYNAME = '{http://owncloud.org/ns}owner-display-name';
	public const CHECKSUMS_PROPERTYNAME = '{http://owncloud.org/ns}checksums';
	public const DATA_FINGERPRINT_PROPERTYNAME = '{http://owncloud.org/ns}data-fingerprint';
	public const HAS_PREVIEW_PROPERTYNAME = '{http://nextcloud.org/ns}has-preview';
	public const MOUNT_TYPE_PROPERTYNAME = '{http://nextcloud.org/ns}mount-type';
	public const IS_ENCRYPTED_PROPERTYNAME = '{http://nextcloud.org/ns}is-encrypted';
	public const METADATA_ETAG_PROPERTYNAME = '{http://nextcloud.org/ns}metadata_etag';
	public const UPLOAD_TIME_PROPERTYNAME = '{http://nextcloud.org/ns}upload_time';
	public const CREATION_TIME_PROPERTYNAME = '{http://nextcloud.org/ns}creation_time';
	public const SHARE_NOTE = '{http://nextcloud.org/ns}note';
	public const SUBFOLDER_COUNT_PROPERTYNAME = '{http://nextcloud.org/ns}contained-folder-count';
	public const SUBFILE_COUNT_PROPERTYNAME = '{http://nextcloud.org/ns}contained-file-count';
	public const FILE_METADATA_SIZE = '{http://nextcloud.org/ns}file-metadata-size';

	/** Reference to main server object */
	private ?Server $server = null;
	private Tree $tree;
	private IUserSession $userSession;

	/**
	 * Whether this is public webdav.
	 * If true, some returned information will be stripped off.
	 */
	private bool $isPublic;
	private bool $downloadAttachment;
	private IConfig $config;
	private IRequest $request;
	private IPreview $previewManager;

	public function __construct(Tree $tree,
								IConfig $config,
								IRequest $request,
								IPreview $previewManager,
								IUserSession $userSession,
								bool $isPublic = false,
								bool $downloadAttachment = true) {
		$this->tree = $tree;
		$this->config = $config;
		$this->request = $request;
		$this->userSession = $userSession;
		$this->isPublic = $isPublic;
		$this->downloadAttachment = $downloadAttachment;
		$this->previewManager = $previewManager;
	}

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by \Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the required event subscriptions.
	 *
	 * @return void
	 */
	public function initialize(Server $server) {
		$server->xml->namespaceMap[self::NS_OWNCLOUD] = 'oc';
		$server->xml->namespaceMap[self::NS_NEXTCLOUD] = 'nc';
		$server->protectedProperties[] = self::FILEID_PROPERTYNAME;
		$server->protectedProperties[] = self::INTERNAL_FILEID_PROPERTYNAME;
		$server->protectedProperties[] = self::PERMISSIONS_PROPERTYNAME;
		$server->protectedProperties[] = self::SHARE_PERMISSIONS_PROPERTYNAME;
		$server->protectedProperties[] = self::OCM_SHARE_PERMISSIONS_PROPERTYNAME;
		$server->protectedProperties[] = self::SHARE_ATTRIBUTES_PROPERTYNAME;
		$server->protectedProperties[] = self::SIZE_PROPERTYNAME;
		$server->protectedProperties[] = self::DOWNLOADURL_PROPERTYNAME;
		$server->protectedProperties[] = self::OWNER_ID_PROPERTYNAME;
		$server->protectedProperties[] = self::OWNER_DISPLAY_NAME_PROPERTYNAME;
		$server->protectedProperties[] = self::CHECKSUMS_PROPERTYNAME;
		$server->protectedProperties[] = self::DATA_FINGERPRINT_PROPERTYNAME;
		$server->protectedProperties[] = self::HAS_PREVIEW_PROPERTYNAME;
		$server->protectedProperties[] = self::MOUNT_TYPE_PROPERTYNAME;
		$server->protectedProperties[] = self::IS_ENCRYPTED_PROPERTYNAME;
		$server->protectedProperties[] = self::SHARE_NOTE;

		// normally these cannot be changed (RFC4918), but we want them modifiable through PROPPATCH
		$allowedProperties = ['{DAV:}getetag'];
		$server->protectedProperties = array_diff($server->protectedProperties, $allowedProperties);

		$this->server = $server;
		$this->server->on('propFind', [$this, 'handleGetProperties']);
		$this->server->on('propPatch', [$this, 'handleUpdateProperties']);
		$this->server->on('afterBind', [$this, 'sendFileIdHeader']);
		$this->server->on('afterWriteContent', [$this, 'sendFileIdHeader']);
		$this->server->on('afterMethod:GET', [$this,'httpGet']);
		$this->server->on('afterMethod:GET', [$this, 'handleDownloadToken']);
		$this->server->on('afterResponse', function ($request, ResponseInterface $response) {
			$body = $response->getBody();
			if (is_resource($body)) {
				fclose($body);
			}
		});
		$this->server->on('beforeMove', [$this, 'checkMove']);
	}

	/**
	 * Plugin that checks if a move can actually be performed.
	 *
	 * @param string $source source path
	 * @param string $destination destination path
	 * @throws Forbidden
	 * @throws NotFound
	 */
	public function checkMove($source, $destination) {
		$sourceNode = $this->tree->getNodeForPath($source);
		if (!$sourceNode instanceof Node) {
			return;
		}
		[$sourceDir,] = \Sabre\Uri\split($source);
		[$destinationDir,] = \Sabre\Uri\split($destination);

		if ($sourceDir !== $destinationDir) {
			$sourceNodeFileInfo = $sourceNode->getFileInfo();
			if ($sourceNodeFileInfo === null) {
				throw new NotFound($source . ' does not exist');
			}

			if (!$sourceNodeFileInfo->isDeletable()) {
				throw new Forbidden($source . " cannot be deleted");
			}
		}
	}

	/**
	 * This sets a cookie to be able to recognize the start of the download
	 * the content must not be longer than 32 characters and must only contain
	 * alphanumeric characters
	 *
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 */
	public function handleDownloadToken(RequestInterface $request, ResponseInterface $response) {
		$queryParams = $request->getQueryParameters();

		/**
		 * this sets a cookie to be able to recognize the start of the download
		 * the content must not be longer than 32 characters and must only contain
		 * alphanumeric characters
		 */
		if (isset($queryParams['downloadStartSecret'])) {
			$token = $queryParams['downloadStartSecret'];
			if (!isset($token[32])
				&& preg_match('!^[a-zA-Z0-9]+$!', $token) === 1) {
				// FIXME: use $response->setHeader() instead
				setcookie('ocDownloadStarted', $token, time() + 20, '/');
			}
		}
	}

	/**
	 * Add headers to file download
	 *
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 */
	public function httpGet(RequestInterface $request, ResponseInterface $response) {
		// Only handle valid files
		$node = $this->tree->getNodeForPath($request->getPath());
		if (!($node instanceof IFile)) {
			return;
		}

		// adds a 'Content-Disposition: attachment' header in case no disposition
		// header has been set before
		if ($this->downloadAttachment &&
			$response->getHeader('Content-Disposition') === null) {
			$filename = $node->getName();
			if ($this->request->isUserAgent(
				[
					Request::USER_AGENT_IE,
					Request::USER_AGENT_ANDROID_MOBILE_CHROME,
					Request::USER_AGENT_FREEBOX,
				])) {
				$response->addHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"');
			} else {
				$response->addHeader('Content-Disposition', 'attachment; filename*=UTF-8\'\'' . rawurlencode($filename)
													 . '; filename="' . rawurlencode($filename) . '"');
			}
		}

		if ($node instanceof \OCA\DAV\Connector\Sabre\File) {
			//Add OC-Checksum header
			$checksum = $node->getChecksum();
			if ($checksum !== null && $checksum !== '') {
				$response->addHeader('OC-Checksum', $checksum);
			}
		}
		$response->addHeader('X-Accel-Buffering', 'no');
	}

	/**
	 * Adds all ownCloud-specific properties
	 *
	 * @param PropFind $propFind
	 * @param \Sabre\DAV\INode $node
	 * @return void
	 */
	public function handleGetProperties(PropFind $propFind, \Sabre\DAV\INode $node) {
		$httpRequest = $this->server->httpRequest;

		if ($node instanceof \OCA\DAV\Connector\Sabre\Node) {
			/**
			 * This was disabled, because it made dir listing throw an exception,
			 * so users were unable to navigate into folders where one subitem
			 * is blocked by the files_accesscontrol app, see:
			 * https://github.com/nextcloud/files_accesscontrol/issues/65
			 * if (!$node->getFileInfo()->isReadable()) {
			 *     // avoid detecting files through this means
			 *     throw new NotFound();
			 * }
			 */

			$propFind->handle(self::FILEID_PROPERTYNAME, function () use ($node) {
				return $node->getFileId();
			});

			$propFind->handle(self::INTERNAL_FILEID_PROPERTYNAME, function () use ($node) {
				return $node->getInternalFileId();
			});

			$propFind->handle(self::PERMISSIONS_PROPERTYNAME, function () use ($node) {
				$perms = $node->getDavPermissions();
				if ($this->isPublic) {
					// remove mount information
					$perms = str_replace(['S', 'M'], '', $perms);
				}
				return $perms;
			});

			$propFind->handle(self::SHARE_PERMISSIONS_PROPERTYNAME, function () use ($node, $httpRequest) {
				$user = $this->userSession->getUser();
				if ($user === null) {
					return null;
				}
				return $node->getSharePermissions(
					$user->getUID()
				);
			});

			$propFind->handle(self::OCM_SHARE_PERMISSIONS_PROPERTYNAME, function () use ($node, $httpRequest): ?string {
				$user = $this->userSession->getUser();
				if ($user === null) {
					return null;
				}
				$ncPermissions = $node->getSharePermissions(
					$user->getUID()
				);
				$ocmPermissions = $this->ncPermissions2ocmPermissions($ncPermissions);
				return json_encode($ocmPermissions, JSON_THROW_ON_ERROR);
			});

			$propFind->handle(self::SHARE_ATTRIBUTES_PROPERTYNAME, function () use ($node, $httpRequest) {
				return json_encode($node->getShareAttributes(), JSON_THROW_ON_ERROR);
			});

			$propFind->handle(self::GETETAG_PROPERTYNAME, function () use ($node): string {
				return $node->getETag();
			});

			$propFind->handle(self::OWNER_ID_PROPERTYNAME, function () use ($node): ?string {
				$owner = $node->getOwner();
				if (!$owner) {
					return null;
				} else {
					return $owner->getUID();
				}
			});
			$propFind->handle(self::OWNER_DISPLAY_NAME_PROPERTYNAME, function () use ($node): ?string {
				$owner = $node->getOwner();
				if (!$owner) {
					return null;
				} else {
					return $owner->getDisplayName();
				}
			});

			$propFind->handle(self::HAS_PREVIEW_PROPERTYNAME, function () use ($node) {
				return json_encode($this->previewManager->isAvailable($node->getFileInfo()), JSON_THROW_ON_ERROR);
			});
			$propFind->handle(self::SIZE_PROPERTYNAME, function () use ($node): int|float {
				return $node->getSize();
			});
			$propFind->handle(self::MOUNT_TYPE_PROPERTYNAME, function () use ($node) {
				return $node->getFileInfo()->getMountPoint()->getMountType();
			});

			$propFind->handle(self::SHARE_NOTE, function () use ($node, $httpRequest): ?string {
				$user = $this->userSession->getUser();
				if ($user === null) {
					return null;
				}
				return $node->getNoteFromShare(
					$user->getUID()
				);
			});

			$propFind->handle(self::DATA_FINGERPRINT_PROPERTYNAME, function () use ($node) {
				return $this->config->getSystemValue('data-fingerprint', '');
			});
			$propFind->handle(self::CREATIONDATE_PROPERTYNAME, function () use ($node) {
				return (new \DateTimeImmutable())
					->setTimestamp($node->getFileInfo()->getCreationTime())
					->format(\DateTimeInterface::ATOM);
			});
			$propFind->handle(self::CREATION_TIME_PROPERTYNAME, function () use ($node) {
				return $node->getFileInfo()->getCreationTime();
			});
			/**
			 * Return file/folder name as displayname. The primary reason to
			 * implement it this way is to avoid costly fallback to
			 * CustomPropertiesBackend (esp. visible when querying all files
			 * in a folder).
			 */
			$propFind->handle(self::DISPLAYNAME_PROPERTYNAME, function () use ($node) {
				return $node->getName();
			});
		}

		if ($node instanceof \OCA\DAV\Connector\Sabre\File) {
			$propFind->handle(self::DOWNLOADURL_PROPERTYNAME, function () use ($node) {
				try {
					$directDownloadUrl = $node->getDirectDownload();
					if (isset($directDownloadUrl['url'])) {
						return $directDownloadUrl['url'];
					}
				} catch (StorageNotAvailableException $e) {
					return false;
				} catch (ForbiddenException $e) {
					return false;
				}
				return false;
			});

			$propFind->handle(self::CHECKSUMS_PROPERTYNAME, function () use ($node) {
				$checksum = $node->getChecksum();
				if ($checksum === null || $checksum === '') {
					return null;
				}

				return new ChecksumList($checksum);
			});

			$propFind->handle(self::UPLOAD_TIME_PROPERTYNAME, function () use ($node) {
				return $node->getFileInfo()->getUploadTime();
			});

			if ($this->config->getSystemValueBool('enable_file_metadata', true)) {
				$propFind->handle(self::FILE_METADATA_SIZE, function () use ($node) {
					if (!str_starts_with($node->getFileInfo()->getMimetype(), 'image')) {
						return json_encode((object)[], JSON_THROW_ON_ERROR);
					}

					if ($node->hasMetadata('size')) {
						$sizeMetadata = $node->getMetadata('size');
					} else {
						// This code path should not be called since we try to preload
						// the metadata when loading the folder or the search results
						// in one go
						$metadataManager = \OC::$server->get(IMetadataManager::class);
						$sizeMetadata = $metadataManager->fetchMetadataFor('size', [$node->getId()])[$node->getId()];

						// TODO would be nice to display this in the profiler...
						\OC::$server->get(LoggerInterface::class)->debug('Inefficient fetching of metadata');
					}

					return json_encode((object)$sizeMetadata->getMetadata(), JSON_THROW_ON_ERROR);
				});
			}
		}

		if ($node instanceof Directory) {
			$propFind->handle(self::SIZE_PROPERTYNAME, function () use ($node) {
				return $node->getSize();
			});

			$propFind->handle(self::IS_ENCRYPTED_PROPERTYNAME, function () use ($node) {
				return $node->getFileInfo()->isEncrypted() ? '1' : '0';
			});

			$requestProperties = $propFind->getRequestedProperties();

			// TODO detect dynamically which metadata groups are requested and
			// preload all of them and not just size
			if ($this->config->getSystemValueBool('enable_file_metadata', true)
				&& in_array(self::FILE_METADATA_SIZE, $requestProperties, true)) {
				// Preloading of the metadata
				$fileIds = [];
				foreach ($node->getChildren() as $child) {
					/** @var \OCP\Files\Node|Node $child */
					if (str_starts_with($child->getFileInfo()->getMimeType(), 'image/')) {
						/** @var File $child */
						$fileIds[] = $child->getFileInfo()->getId();
					}
				}
				/** @var IMetaDataManager $metadataManager */
				$metadataManager = \OC::$server->get(IMetadataManager::class);
				$preloadedMetadata = $metadataManager->fetchMetadataFor('size', $fileIds);
				foreach ($node->getChildren() as $child) {
					/** @var \OCP\Files\Node|Node $child */
					if (str_starts_with($child->getFileInfo()->getMimeType(), 'image')) {
						/** @var File $child */
						$child->setMetadata('size', $preloadedMetadata[$child->getFileInfo()->getId()]);
					}
				}
			}

			if (in_array(self::SUBFILE_COUNT_PROPERTYNAME, $requestProperties, true)
				|| in_array(self::SUBFOLDER_COUNT_PROPERTYNAME, $requestProperties, true)) {
				$nbFiles = 0;
				$nbFolders = 0;
				foreach ($node->getChildren() as $child) {
					if ($child instanceof File) {
						$nbFiles++;
					} elseif ($child instanceof Directory) {
						$nbFolders++;
					}
				}

				$propFind->handle(self::SUBFILE_COUNT_PROPERTYNAME, $nbFiles);
				$propFind->handle(self::SUBFOLDER_COUNT_PROPERTYNAME, $nbFolders);
			}
		}
	}

	/**
	 * translate Nextcloud permissions to OCM Permissions
	 *
	 * @param $ncPermissions
	 * @return array
	 */
	protected function ncPermissions2ocmPermissions($ncPermissions) {
		$ocmPermissions = [];

		if ($ncPermissions & Constants::PERMISSION_SHARE) {
			$ocmPermissions[] = 'share';
		}

		if ($ncPermissions & Constants::PERMISSION_READ) {
			$ocmPermissions[] = 'read';
		}

		if (($ncPermissions & Constants::PERMISSION_CREATE) ||
			($ncPermissions & Constants::PERMISSION_UPDATE)) {
			$ocmPermissions[] = 'write';
		}

		return $ocmPermissions;
	}

	/**
	 * Update ownCloud-specific properties
	 *
	 * @param string $path
	 * @param PropPatch $propPatch
	 *
	 * @return void
	 */
	public function handleUpdateProperties($path, PropPatch $propPatch) {
		$node = $this->tree->getNodeForPath($path);
		if (!($node instanceof \OCA\DAV\Connector\Sabre\Node)) {
			return;
		}

		$propPatch->handle(self::LASTMODIFIED_PROPERTYNAME, function ($time) use ($node) {
			if (empty($time)) {
				return false;
			}
			$node->touch($time);
			return true;
		});
		$propPatch->handle(self::GETETAG_PROPERTYNAME, function ($etag) use ($node) {
			if (empty($etag)) {
				return false;
			}
			return $node->setEtag($etag) !== -1;
		});
		$propPatch->handle(self::CREATIONDATE_PROPERTYNAME, function ($time) use ($node) {
			if (empty($time)) {
				return false;
			}
			$dateTime = new \DateTimeImmutable($time);
			$node->setCreationTime($dateTime->getTimestamp());
			return true;
		});
		$propPatch->handle(self::CREATION_TIME_PROPERTYNAME, function ($time) use ($node) {
			if (empty($time)) {
				return false;
			}
			$node->setCreationTime((int) $time);
			return true;
		});
		/**
		 * Disable modification of the displayname property for files and
		 * folders via PROPPATCH. See PROPFIND for more information.
		 */
		$propPatch->handle(self::DISPLAYNAME_PROPERTYNAME, function ($displayName) {
			return 403;
		});
	}

	/**
	 * @param string $filePath
	 * @param \Sabre\DAV\INode $node
	 * @throws \Sabre\DAV\Exception\BadRequest
	 */
	public function sendFileIdHeader($filePath, \Sabre\DAV\INode $node = null) {
		// we get the node for the given $filePath here because in case of afterCreateFile $node is the parent folder
		if (!$this->server->tree->nodeExists($filePath)) {
			return;
		}
		$node = $this->server->tree->getNodeForPath($filePath);
		if ($node instanceof \OCA\DAV\Connector\Sabre\Node) {
			$fileId = $node->getFileId();
			if (!is_null($fileId)) {
				$this->server->httpResponse->setHeader('OC-FileId', $fileId);
			}
		}
	}
}
