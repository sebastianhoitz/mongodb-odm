<?php

namespace Doctrine\ODM\MongoDB\Tests\Mapping;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadataFactory;

require_once __DIR__ . '/../../../../../TestInit.php';

class BasicInheritanceMappingTest extends \Doctrine\ODM\MongoDB\Tests\BaseTest
{
    private $factory;

    public function setUp()
    {
        parent::setUp();
        $this->factory = new ClassMetadataFactory($this->dm);
    }
    
    /**
     * @expectedException Doctrine\ODM\MongoDB\MongoDBException
     */
    public function testGetMetadataForTransientClassThrowsException()
    {
        $this->factory->getMetadataFor('Doctrine\ODM\MongoDB\Tests\Mapping\TransientBaseClass');
    }
    
    public function testGetMetadataForSubclassWithTransientBaseClass()
    {
        $class = $this->factory->getMetadataFor('Doctrine\ODM\MongoDB\Tests\Mapping\DocumentSubClass');
        
        $this->assertTrue(empty($class->subClasses));
        $this->assertTrue(empty($class->parentClasses));
        $this->assertTrue(isset($class->fieldMappings['id']));
        $this->assertTrue(isset($class->fieldMappings['name']));
    }
    
    public function testGetMetadataForSubclassWithMappedSuperclass()
    {
        $class = $this->factory->getMetadataFor('Doctrine\ODM\MongoDB\Tests\Mapping\DocumentSubClass2');
        
        $this->assertTrue(empty($class->subClasses));
        $this->assertTrue(empty($class->parentClasses));
        
        $this->assertTrue(isset($class->fieldMappings['mapped1']));
        $this->assertTrue(isset($class->fieldMappings['mapped2']));
        $this->assertTrue(isset($class->fieldMappings['id']));
        $this->assertTrue(isset($class->fieldMappings['name']));
        
        $this->assertFalse(isset($class->fieldMappings['mapped1']['inherited']));
        $this->assertFalse(isset($class->fieldMappings['mapped2']['inherited']));
        $this->assertFalse(isset($class->fieldMappings['transient']));
        
        $this->assertTrue(isset($class->fieldMappings['mappedRelated1']));
    }

    /**
     * @group DDC-388
     */
    public function testSerializationWithPrivateFieldsFromMappedSuperclass()
    {
        $class = $this->factory->getMetadataFor(__NAMESPACE__ . '\\DocumentSubClass2');

        $class2 = unserialize(serialize($class));

        $this->assertTrue(isset($class2->reflFields['mapped1']));
        $this->assertTrue(isset($class2->reflFields['mapped2']));
        $this->assertTrue(isset($class2->reflFields['mappedRelated1']));
    }
}

class TransientBaseClass
{
    private $transient1;
    private $transient2;
}

/** @Document */
class DocumentSubClass extends TransientBaseClass
{
    /** @Id */
    private $id;

    /** @String */
    private $name;
}

/** @MappedSuperclass */
class MappedSuperclassBase
{
    /** @String */
    private $mapped1;
    /** @String */
    private $mapped2;

    /**
     * @ReferenceOne(targetDocument="MappedSuperclassRelated1")
     */
    private $mappedRelated1;

    private $transient;
}

/** @Document */
class DocumentSubClass2 extends MappedSuperclassBase
{
    /** @Id */
    private $id;

    /** @String */
    private $name;
}