<?php

namespace Sli\ExtJsIntegrationBundle\DataMapping;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Security\Core\SecurityContext;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\ClassMetadataInfo as CMI;
use Sli\AuxBundle\Util\Toolkit;
use Sli\AuxBundle\Util\JavaBeansObjectFieldsManager;
use Doctrine\Common\Collections\Collection;

/**
 * Service is responsible for inspect the data that usually comes from client-side and update the database. All
 * relation types supported by Doctrine are supported by this service as well - ONE_TO_ONE, ONE_TO_MANY,
 * MANY_TO_ONE, MANY_TO_MANY. Service is capable to properly update all relation types ( owning, inversed-side )
 * even when entity classes do not define them. Also this service is smart enough to properly cast provided
 * values to the types are defined in doctrine mappings, that is - if string "10.2" is provided, but the field
 * it was provided for is mapped as "float", then the conversion to float value will be automatically done - this is
 * especially useful if your setter method have some logic not just assigning a new value to a class field.
 *
 * In order for this class to work, your security principal ( implementation of UserInterface ),
 * must implement {@class PreferencesAwareUserInterface}.
 *
 * @author Sergei Lissovski <sergei.lissovski@gmail.com>
 */
class EntityDataMapperService
{
    private $em;
    private $sc;
    private $fm;
    private $paramsProvider;

    public function __construct(
        EntityManager $em, SecurityContext $sc, JavaBeansObjectFieldsManager $fm,
        MethodInvocationParametersProviderInterface $paramsProvider)
    {
        $this->em = $em;
        $this->sc = $sc;
        $this->fm = $fm;
        $this->paramsProvider = $paramsProvider;
    }

    /**
     * @throws \RuntimeException
     * @return array
     */
    protected function getUserPreferences()
    {
        /* @var PreferencesAwareUserInterface $u */
        $u = $this->sc->getToken()->getUser();
        if (!$u) {
            throw new \RuntimeException('No authenticated user available in your session.');
        } else if (!($u instanceof PreferencesAwareUserInterface)) {
            throw new \RuntimeException(
                'Currently authenticated user must implement PreferencesAwareUserInterface !'
            );
        }

        return $u->getPreferences();
    }

    public function convertDate($clientValue)
    {
        if ($clientValue != '') {
            $p = $this->getUserPreferences();

            $keyName = PreferencesAwareUserInterface::SETTINGS_DATE_FORMAT;
            if (!isset($p[$keyName])) {
                throw new \RuntimeException(
                    sprintf('User preferences must contain configuration for "%s"', $keyName)
                );
            }
            $format = $p[$keyName];

            $rawClientValue = $clientValue;
            $clientValue = \DateTime::createFromFormat($format, $clientValue);
            if (!$clientValue) {
                throw new \RuntimeException(
                    "Unable to map a date, unable to transform date-value of '$rawClientValue' to '$format' format."
                );
            }
            return $clientValue;
        } else {
            return null;
        }
    }

    public function convertValue($clientValue, $fieldType)
    {
        switch ($fieldType) {
            case 'boolean':
                return $this->convertBoolean($clientValue);
            case 'date':
                return $this->convertDate($clientValue);
            case 'datetime':
                return $this->convertDate($clientValue);
//                throw new \RuntimeException('No support for mapping "datetime" field yet!');
        }

        return $clientValue;
    }

    public function convertBoolean($clientValue)
    {
        return 'on' === $clientValue || 1 == $clientValue || 'true' === $clientValue;
    }

    /**
     * Be aware, that "id" property will never be mapped to you entities even if it is provided
     * in $params, we presume that it always be generated automatically.
     *
     * @param Object $entity
     * @param array $params  Data usually received from client-side
     * @param array $allowedFields  Fields names you want to allow have mapped
     * @throws \RuntimeException
     */
    public function mapEntity($entity, array $params, array $allowedFields)
    {
        $entityMethods = get_class_methods($entity);
        $metadata = $this->em->getClassMetadata(get_class($entity));

        foreach ($metadata->getFieldNames() as $fieldName) {
            if (!in_array($fieldName, $allowedFields) || 'id' == $fieldName) { // ID is always generated dynamically
                continue;
            }

            if (isset($params[$fieldName])) {
                $value = $params[$fieldName];
                $mapping = $metadata->getFieldMapping($fieldName);

                // if a field is number and at the same time its value was not provided,
                // then we are not touching it at all, if the model has specified
                // a default value for it - fine, everything's gonna be fine, otherwise
                // Doctrine will look if this field isNullable etc ... and throw
                // an exception if needed
                if (     !(in_array($mapping['type'], array('integer', 'smallint', 'bigint', 'decimal', 'float'))
                      && '' === $value)) {
                    try {
                        $methodParams = $this->paramsProvider->getParameters(get_class($entity), $this->fm->formatSetterName($fieldName));
                        $methodParams = array_merge(array($this->convertValue($value, $mapping['type'])), $methodParams);

                        $this->fm->set($entity, $fieldName, $methodParams);
                    } catch (\Exception $e) {
                        throw new \RuntimeException(
                            "Something went wrong during mapping of a scalar field '$fieldName' - ".$e->getMessage(), null, $e
                        );
                    }
                }
            }
        }
        
        foreach ($metadata->getAssociationMappings() as $mapping) {
            $fieldName = $mapping['fieldName'];

            if (!in_array($fieldName, $allowedFields)) {
                continue;
            }
            if (isset($params[$fieldName]) && null !== $params[$fieldName]) {
                if (in_array($mapping['type'], array(CMI::ONE_TO_ONE, CMI::MANY_TO_ONE))) {
                    $rawValue = $params[$fieldName];

                    $methodParams = $this->paramsProvider->getParameters(get_class($entity), $this->fm->formatSetterName($fieldName));

                    if ('-' == $rawValue) {
                        $this->fm->set($entity, $fieldName, array_merge(array(null), $methodParams));
                    } else {
                        $value = $this->em->getRepository($mapping['targetEntity'])->find($rawValue);
                        if ($value) {
                            $this->fm->set($entity, $fieldName, array_merge(array($value), $methodParams));
                        }
                    }
                } else { // one_to_many, many_to_many
                    $rawValue = $params[$fieldName];
                    $col = $metadata->getFieldValue($entity, $fieldName);

                    // if it is a new entity ( you should remember, the entity's constructor is not invoked )
                    // it will have no collection initialized, because this usually happens in the constructor
                    if (!$col) {
                        $col = new ArrayCollection();
                        $this->fm->set($entity, $fieldName, array($col));
                    }

                    $oldIds = Toolkit::extractIds($col);
                    $newIds = is_array($rawValue) ? $rawValue : explode(', ', $rawValue);
                    $idsToDelete = array_diff($oldIds, $newIds);
                    $idsToAdd = array_diff($newIds, $oldIds);

                    $entitiesToDelete = $this->getEntitiesByIds($idsToDelete, $mapping['targetEntity']);
                    $entitiesToAdd = $this->getEntitiesByIds($idsToAdd, $mapping['targetEntity']);

                    /*
                     * At first it will be checked if removeXXX/addXXX methods exist, if they
                     * do, then they will be used, otherwise we will try to manage
                     * relation manually
                     */
                    $removeMethod = 'remove'.ucfirst(Toolkit::singlifyWord($fieldName));
                    if (in_array($removeMethod, $entityMethods) && count($idsToDelete) > 0) {
                        foreach ($entitiesToDelete as $refEntity) {
                            $methodParams = array_merge(
                                array($refEntity),
                                $this->paramsProvider->getParameters(get_class($entity), $removeMethod)
                            );
                            call_user_func_array(array($entity, $removeMethod), $methodParams);
                        }
                    } else {
                        foreach ($entitiesToDelete as $refEntity) {
                            if ($col->contains($refEntity)) {
                                $col->removeElement($refEntity);

                                if (CMI::MANY_TO_MANY == $mapping['type']) {
                                    $refMetadata = $this->em->getClassMetadata(get_class($refEntity));

                                    // bidirectional
                                    if ($refMetadata->hasAssociation($mapping['mappedBy'])) {
                                        $inversedCol = $refMetadata->getFieldValue($refEntity, $mapping['mappedBy']);
                                        if ($inversedCol instanceof Collection) {
                                            $inversedCol->removeElement($entity);
                                        }
                                    }
                                } else {
                                    // nulling the other side of relation
                                    $this->fm->set($refEntity, $mapping['mappedBy'], array(null));
                                }
                            }
                        }
                    }

                    $addMethod = 'add'.ucfirst(Toolkit::singlifyWord($fieldName));
                    if (in_array($addMethod, $entityMethods) && count($idsToAdd) > 0) {
                        foreach ($entitiesToAdd as $refEntity) {
                            $methodParams = array_merge(
                                array($refEntity),
                                $this->paramsProvider->getParameters(get_class($entity), $addMethod)
                            );
                            call_user_func_array(array($entity, $addMethod), $methodParams);
                        }
                    } else {
                        foreach ($entitiesToAdd as $refEntity) {
                            if (!$col->contains($refEntity)) {
                                $col->add($refEntity);

                                if (CMI::MANY_TO_MANY == $mapping['type']) {
                                    $refMetadata = $this->em->getClassMetadata(get_class($refEntity));

                                    // bidirectional
                                    if ($refMetadata->hasAssociation($mapping['mappedBy'])) {
                                        $inversedCol = $refMetadata->getFieldValue($refEntity, $mapping['mappedBy']);
                                        if ($inversedCol instanceof Collection) {
                                            $inversedCol->add($entity);
                                        }
                                    }
                                } else {
                                    $this->fm->set($refEntity, $mapping['mappedBy'], array($entity));
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function getEntitiesByIds(array $ids, $entityFqcn)
    {
        if (count($ids) == 0) {
            return array();
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('e')
           ->from($entityFqcn, 'e')
           ->where($qb->expr()->in('e.id', $ids));

        return $qb->getQuery()->getResult();
    }
}