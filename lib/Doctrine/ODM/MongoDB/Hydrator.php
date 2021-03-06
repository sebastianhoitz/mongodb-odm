<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ODM\MongoDB;

use Doctrine\ODM\MongoDB\Query,
    Doctrine\ODM\MongoDB\Mapping\ClassMetadata,
    Doctrine\ODM\MongoDB\Mapping\Types\Type,
    Doctrine\ODM\MongoDB\PersistentCollection,
    Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\ODM\MongoDB\Event\LifecycleEventArgs,
    Doctrine\ODM\MongoDB\Event\PreLoadEventArgs;

/**
 * The Hydrator class is responsible for converting a document from MongoDB
 * which is an array to classes and collections based on the mapping of the document
 *
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.doctrine-project.com
 * @since       1.0
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 */
class Hydrator
{
    /**
     * The DocumentManager associated with this Hydrator
     *
     * @var Doctrine\ODM\MongoDB\DocumentManager
     */
    private $dm;

    /**
     * The EventManager associated with this Hydrator
     *
     * @var Doctrine\Common\EventManager
     */
    private $evm;

    /**
     * Mongo command prefix
     * @var string
     */
    private $cmd;

    /**
     * Create a new Hydrator instance
     *
     * @param Doctrine\ODM\MongoDB\DocumentManager $dm
     */
    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
        $this->evm = $this->dm->getEventManager();
        $this->cmd = $dm->getConfiguration()->getMongoCmd();
    }

    /**
     * Hydrate array of MongoDB document data into the given document object.
     *
     * @param object $document  The document object to hydrate the data into.
     * @param array $data The array of document data.
     * @return array $values The array of hydrated values.
     */
    public function hydrate($document, &$data)
    {
        $metadata = $this->dm->getClassMetadata(get_class($document));

        if (isset($metadata->lifecycleCallbacks[ODMEvents::preLoad])) {
            $args = array(&$data);
            $metadata->invokeLifecycleCallbacks(ODMEvents::preLoad, $document, $args);
        }
        if ($this->evm->hasListeners(ODMEvents::preLoad)) {
            $this->evm->dispatchEvent(ODMEvents::preLoad, new PreLoadEventArgs($document, $this->dm, $data));
        }

        if (isset($metadata->alsoLoadMethods)) {
            foreach ($metadata->alsoLoadMethods as $fieldName => $method) {
                if (isset($data[$fieldName])) {
                    $document->$method($data[$fieldName]);
                }
            }
        }
        foreach ($metadata->fieldMappings as $mapping) {
            if (isset($mapping['alsoLoadFields'])) {
                $rawValue = null;
                $names = isset($mapping['alsoLoadFields']) ? $mapping['alsoLoadFields'] : array();
                array_unshift($names, $mapping['name']);
                foreach ($names as $name) {
                    if (isset($data[$name])) {
                        $rawValue = $data[$name];
                        break;
                    }
                }
            } else {
                $rawValue = isset($data[$mapping['name']]) ? $data[$mapping['name']] : null;
            }
            if ($rawValue === null) {
                continue;
            }

            $value = null;

            // Hydrate embedded
            if (isset($mapping['embedded'])) {
                if ($mapping['type'] === 'one') {
                    $embeddedDocument = $rawValue;
                    $className = $this->dm->getClassNameFromDiscriminatorValue($mapping, $embeddedDocument);
                    $embeddedMetadata = $this->dm->getClassMetadata($className);
                    $value = $embeddedMetadata->newInstance();
                    $this->hydrate($value, $embeddedDocument);
                    $data[$mapping['name']] = $embeddedDocument;
                    $this->dm->getUnitOfWork()->registerManaged($value, null, $embeddedDocument);
                } elseif ($mapping['type'] === 'many') {
                    $embeddedDocuments = $rawValue;
                    $coll = new PersistentCollection(new ArrayCollection());
                    foreach ($embeddedDocuments as $key => $embeddedDocument) {
                        $className = $this->dm->getClassNameFromDiscriminatorValue($mapping, $embeddedDocument);
                        $embeddedMetadata = $this->dm->getClassMetadata($className);
                        $embeddedDocumentObject = $embeddedMetadata->newInstance();
                        $this->hydrate($embeddedDocumentObject, $embeddedDocument);
                        $data[$mapping['name']][$key] = $embeddedDocument;
                        $this->dm->getUnitOfWork()->registerManaged($embeddedDocumentObject, null, $embeddedDocument);
                        $coll->add($embeddedDocumentObject);
                    }
                    $coll->setOwner($document, $mapping);
                    $coll->takeSnapshot();
                    $value = $coll;
                }
            // Hydrate reference
            } elseif (isset($mapping['reference'])) {
                $reference = $rawValue;
                if ($mapping['type'] === 'one' && isset($reference[$this->cmd . 'id'])) {
                    $className = $this->dm->getClassNameFromDiscriminatorValue($mapping, $reference);
                    $targetMetadata = $this->dm->getClassMetadata($className);
                    $id = $targetMetadata->getPHPIdentifierValue($reference[$this->cmd . 'id']);
                    $value = $this->dm->getReference($className, $id);
                } elseif ($mapping['type'] === 'many' && (is_array($reference) || $reference instanceof Collection)) {
                    $references = $reference;
                    $value = new PersistentCollection(new ArrayCollection(), $this->dm);
                    $value->setInitialized(false);
                    $value->setOwner($document, $mapping);

                    // Delay any hydration of reference objects until the collection is
                    // accessed and initialized for the first ime
                    $value->setReferences($references);
                }
                $data[$mapping['name']] = $value;
            // Hydrate regular field
            } else {
                $value = Type::getType($mapping['type'])->convertToPHPValue($rawValue);
                $data[$mapping['name']] = $value;
            }

            // Set hydrated field value to document
            if ($value !== null) {
                $metadata->setFieldValue($document, $mapping['fieldName'], $value);
            }
        }
        // Set the document identifier
        if (isset($data['_id'])) {
            $metadata->setIdentifierValue($document, $data['_id']);
            $data[$metadata->identifier] = $data['_id'];
            unset($data['_id']);
        }

        if (isset($metadata->lifecycleCallbacks[ODMEvents::postLoad])) {
            $metadata->invokeLifecycleCallbacks(ODMEvents::postLoad, $document);
        }
        if ($this->evm->hasListeners(ODMEvents::postLoad)) {
            $this->evm->dispatchEvent(ODMEvents::postLoad, new LifecycleEventArgs($document, $this->dm));
        }

        return $document;
    }
}