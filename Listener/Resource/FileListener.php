<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\CoreBundle\Listener\Resource;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use JMS\DiExtraBundle\Annotation as DI;
use Claroline\CoreBundle\Entity\Resource\File;
use Claroline\CoreBundle\Entity\Resource\Directory;
use Claroline\CoreBundle\Entity\Resource\ResourceNode;
use Claroline\CoreBundle\Form\FileType;
use Claroline\CoreBundle\Event\CopyResourceEvent;
use Claroline\CoreBundle\Event\CreateFormResourceEvent;
use Claroline\CoreBundle\Event\CreateResourceEvent;
use Claroline\CoreBundle\Event\OpenResourceEvent;
use Claroline\CoreBundle\Event\DeleteResourceEvent;
use Claroline\CoreBundle\Event\DownloadResourceEvent;
use Claroline\CoreBundle\Event\CustomActionResourceEvent;
use Claroline\CoreBundle\Event\ExportResourceTemplateEvent;
use Claroline\CoreBundle\Event\ImportResourceTemplateEvent;

/**
 * @DI\Service
 */
class FileListener implements ContainerAwareInterface
{
    private $container;
    private $resourceManager;
    private $om;
    private $sc;
    private $request;
    private $httpKernel;

    /**
     * @DI\InjectParams({
     *     "container" = @DI\Inject("service_container")
     * })
     *
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
        $this->resourceManager = $container->get('claroline.manager.resource_manager');
        $this->om = $container->get('claroline.persistence.object_manager');
        $this->sc = $container->get('security.context');
        $this->request = $container->get('request_stack');
        $this->httpKernel = $container->get('httpKernel');
    }

    /**
     * @DI\Observe("create_form_file")
     *
     * @param CreateFormResourceEvent $event
     */
    public function onCreateForm(CreateFormResourceEvent $event)
    {
        $form = $this->container->get('form.factory')->create(new FileType(true), new File());
        $content = $this->container->get('templating')->render(
            'ClarolineCoreBundle:Resource:createForm.html.twig',
            array(
                'form' => $form->createView(),
                'resourceType' => 'file'
            )
        );
        $event->setResponseContent($content);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("create_file")
     *
     * @param CreateResourceEvent $event
     */
    public function onCreate(CreateResourceEvent $event)
    {
        $request = $this->container->get('request');
        $form = $this->container->get('form.factory')->create(new FileType(true), new File());
        $form->handleRequest($request);

        if ($form->isValid()) {
            $file = $form->getData();
            $tmpFile = $form->get('file')->getData();
            $fileName = $tmpFile->getClientOriginalName();
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);

            //uncompress
            if ($extension === 'zip' && $form->get('uncompress')->getData()) {
                $resources = $this->unzip($tmpFile, $event->getParent());
                $event->setResources($resources);
                //do not process the resources afterwards because nodes have been created with the unzip function.
                $event->setProcess(false);
                $event->stopPropagation();
            } else {
                $filesDir = $this->container->getParameter('claroline.param.files_directory');
                $guid = $this->container->get('claroline.utilities.misc')->generateGuid();
                $guidFile = $guid . '.' . $extension;
                $size = filesize($tmpFile);
                $mimeType = $tmpFile->getClientMimeType();
                $date = date('Y-m-d');
                $dirPath = $this->container->getParameter('claroline.param.files_directory') .
                    DIRECTORY_SEPARATOR .
                    $date;
                $dirExists = file_exists($dirPath) && is_dir($dirPath);

                if (!$dirExists) {
                    $dirExists = mkdir($dirPath);
                }

                if ($dirExists) {
                    $hashName = $date .
                        DIRECTORY_SEPARATOR .
                        $guidFile;
                    $tmpFile->move($filesDir . DIRECTORY_SEPARATOR . $date, $guidFile);
                } else {
                    $hashName = $guidFile;
                    $tmpFile->move($filesDir, $guidFile);
                }
                $file->setSize($size);
                $file->setName($fileName);
                $file->setHashName($hashName);
                $file->setMimeType($mimeType);
                $event->setResources(array($file));
                $event->stopPropagation();
            }

            return;
        }

        $content = $this->container->get('templating')->render(
            'ClarolineCoreBundle:Resource:createForm.html.twig',
            array(
                'form' => $form->createView(),
                'resourceType' => $event->getResourceType()
            )
        );
        $event->setErrorFormContent($content);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("delete_file")
     *
     * @param DeleteResourceEvent $event
     */
    public function onDelete(DeleteResourceEvent $event)
    {
        $hashName = $event->getResource()->getHashName();
        $pathName = $this->container->getParameter('claroline.param.files_directory')
            . DIRECTORY_SEPARATOR
            . $hashName;

        if (file_exists($pathName)) {
            $event->setFiles(array($pathName));
            $this->deleteEmptyDirectory($hashName);
        }

        $event->stopPropagation();
    }

    /**
     * @DI\Observe("copy_file")
     *
     * @param CopyResourceEvent $event
     */
    public function onCopy(CopyResourceEvent $event)
    {
        $newFile = $this->copy($event->getResource());
        $event->setCopy($newFile);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("download_file")
     *
     * @param DownloadResourceEvent $event
     */
    public function onDownload(DownloadResourceEvent $event)
    {
        $file = $event->getResource();
        $hash = $file->getHashName();
        $event->setItem(
            $this->container->getParameter('claroline.param.files_directory') . DIRECTORY_SEPARATOR . $hash
        );
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("open_file")
     *
     * @param OpenResourceEvent $event
     */
    public function onOpen(OpenResourceEvent $event)
    {
        $ds = DIRECTORY_SEPARATOR;
        $resource = $event->getResource();
        $mimeType = $resource->getResourceNode()->getMimeType();
        $playEvent = $this->container->get('claroline.event.event_dispatcher')
                ->dispatch(
                    strtolower(str_replace('/', '_', 'play_file_' . $mimeType)),
                    'PlayFile',
                    array($resource)
                );

        if ($playEvent->getResponse() instanceof Response) {
            $response = $playEvent->getResponse();
        } else {
            $mimeElements = explode('/', $mimeType);
            $baseType = strtolower($mimeElements[0]);
            $fallBackPlayEventName = 'play_file_' . $baseType;
            $fallBackPlayEvent = $this->container->get('claroline.event.event_dispatcher')->dispatch(
                $fallBackPlayEventName,
                'PlayFile',
                array($resource)
            );
            if ($fallBackPlayEvent->getResponse() instanceof Response) {
                $response = $fallBackPlayEvent->getResponse();
            } else {
                $item = $this->container
                    ->getParameter('claroline.param.files_directory') . $ds . $resource->getHashName();
                $file = file_get_contents($item);
                $response = new Response();
                $response->setContent($file);
                $response->headers->set(
                    'Content-Transfer-Encoding',
                    'octet-stream'
                );
                $response->headers->set(
                    'Content-Type',
                    'application/force-download'
                );
                $response->headers->set(
                    'Content-Disposition',
                    'attachment; filename=' . urlencode($resource->getResourceNode()->getName())
                );
                $response->headers->set(
                    'Content-Type',
                    'application/' . pathinfo($item, PATHINFO_EXTENSION)
                );
                $response->headers->set(
                    'Connection',
                    'close'
                );
            }
        }

        $event->setResponse($response);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("update_file_file")
     *
     * @param CustomActionResourceEvent $event
     */
    public function onUpdateFile(CustomActionResourceEvent $event)
    {
        if (!$this->request) {
            throw new NoHttpRequestException();
        }

        $params = array();
        $params['_controller'] = 'ClarolineCoreBundle:File:updateFileForm';
        $params['file'] = $event->getResource()->getId();
        $subRequest = $this->request->getCurrentRequest()->duplicate(array(), null, $params);
        $response = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
        $event->setResponse($response);
    }

    /**
     * @DI\Observe("resource_file_to_template")
     *
     * @param ExportResourceTemplateEvent $event
     */
    public function onExportTemplate(ExportResourceTemplateEvent $event)
    {
        $resource = $event->getResource();
        $hash = $resource->getHashName();
        //@todo: remove this line without breaking everything ('type' is set by the tool listener).
        $config['type'] = 'file';
        $filePath = $this->container->getParameter('claroline.param.files_directory') . DIRECTORY_SEPARATOR . $hash;
        $event->setFiles(array(array('archive_path' => $hash, 'original_path' => $filePath)));
        $event->setConfig($config);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("resource_file_from_template")
     *
     * @param ImportResourceTemplateEvent $event
     */
    public function onImportTemplate(ImportResourceTemplateEvent $event)
    {
        $ds = DIRECTORY_SEPARATOR;
        $files = $event->getFiles();
        $file = new File();
        $extension = pathinfo($files[0], PATHINFO_EXTENSION);
        $hashName = $this->container->get('claroline.utilities.misc')->generateGuid() . "." . $extension;
        $physicalPath = $this->container->getParameter('claroline.param.files_directory') . $ds . $hashName;
        rename($files[0], $physicalPath);
        $size = filesize($physicalPath);
        $file->setSize($size);
        $file->setHashName($hashName);
        $guesser = MimeTypeGuesser::getInstance();
        $file->setMimeType($guesser->guess($physicalPath));
        $event->setResource($file);
        $event->stopPropagation();
    }

    /**
     * Copies a file (no persistence).
     *
     * @param File $resource
     *
     * @return File
     */
    private function copy(File $resource)
    {
        $ds = DIRECTORY_SEPARATOR;
        $newFile = new File();
        $newFile->setSize($resource->getSize());
        $newFile->setName($resource->getName());
        $newFile->setMimeType($resource->getMimeType());
        $hashName = $this->container
            ->get('claroline.utilities.misc')
            ->generateGuid() . '.' . pathinfo($resource->getHashName(), PATHINFO_EXTENSION);
        $newFile->setHashName($hashName);
        $filePath = $this->container->getParameter('claroline.param.files_directory') . $ds . $resource->getHashName();
        $newPath = $this->container->getParameter('claroline.param.files_directory') . $ds . $hashName;
        copy($filePath, $newPath);

        return $newFile;
    }

    private function unzip($archivePath, ResourceNode $root)
    {
        $extractPath = sys_get_temp_dir() .
            DIRECTORY_SEPARATOR .
            $this->container->get('claroline.utilities.misc')->generateGuid() .
            '.zip';

        $archive = new \ZipArchive();

        if ($archive->open($archivePath) === true) {
            $archive->extractTo($extractPath);
            $archive->close();
            $this->om->startFlushSuite();
            $perms = $this->container->get('claroline.manager.rights_manager')->getCustomRoleRights($root);
            $resources = $this->uploadDir($extractPath, $root, $perms, true);
            $this->om->endFlushSuite();

            return $resources;
        }

        throw new \Exception("The archive {$archivePath} can't be opened");
    }

    private function uploadDir($dir, ResourceNode $parent, array $perms, $first = false)
    {
        $resources = [];
        $iterator = new \DirectoryIterator($dir);

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $resources[] = $this->uploadFile($item, $parent, $perms);
            }

            if ($item->isDir() && !$item->isDot()) {
                //create new dir
                $directory = new Directory();
                $directory->setName($item->getBasename());
                $resources[] = $this->resourceManager->create(
                    $directory,
                    $this->resourceManager->getResourceTypeByName('directory'),
                    $this->sc->getToken()->getUser(),
                    $parent->getWorkspace(),
                    $parent,
                    null,
                    $perms
                );

                $this->uploadDir(
                    $dir . DIRECTORY_SEPARATOR . $item->getBasename(),
                    $directory->getResourceNode(),
                    $perms
                );
            }

//            $this->om->forceFlush();
        }

        // set order manually as we are inside a flush suite
        for ($i = 0, $count = count($resources); $i < $count; ++$i) {
            if ($i > 0) {
                $resources[$i]->getResourceNode()
                    ->setPrevious($resources[$i - 1]->getResourceNode());
            }

            if ($i < $count - 1) {
                $resources[$i]->getResourceNode()
                    ->setNext($resources[$i + 1]->getResourceNode());
            }
        }

        if ($first) {
            $previous = $this->om->getRepository('ClarolineCoreBundle:Resource\ResourceNode')
                ->findOneBy(array('parent' => $parent, 'next' => null));

            if ($previous) {
                $previous->setNext($resources[0]->getResourceNode());
            }
        }

        return $resources;
    }

    private function uploadFile(\DirectoryIterator $file, ResourceNode $parent, array $perms)
    {
        $entityFile = new File();
        $fileName = utf8_encode($file->getFilename());
        $size = @filesize($file);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $mimeType = $this->container->get('claroline.utilities.mime_type_guesser')->guess($extension);
        $hashName = $this->container->get('claroline.utilities.misc')->generateGuid() . "." . $extension;
        copy(
            $file->getPathname(),
            $this->container->getParameter('claroline.param.files_directory') . DIRECTORY_SEPARATOR. $hashName
        );
        $entityFile->setSize($size);
        $entityFile->setName($fileName);
        $entityFile->setHashName($hashName);
        $entityFile->setMimeType($mimeType);

        return $this->resourceManager->create(
            $entityFile,
            $this->resourceManager->getResourceTypeByName('file'),
            $this->sc->getToken()->getUser(),
            $parent->getWorkspace(),
            $parent,
            null,
            $perms
        );
    }

    private function deleteEmptyDirectory($pathName)
    {
        $paths = explode(DIRECTORY_SEPARATOR, $pathName);

        if (count($paths) === 2) {
            $dirPath = $this->container->getParameter('claroline.param.files_directory') .
                DIRECTORY_SEPARATOR .
                $paths[0];

            $files = scandir($dirPath);

            if (is_dir($dirPath) && ($files = scandir($dirPath)) && (count($files) <= 2)) {
                rmdir($dirPath);
            }
        }
    }
}
