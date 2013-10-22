<?php

require_once('salesforce/SforcePartnerClient.php');

class SforceObject
    extends SObject {

    public function __construct($response=NULL) {
        parent::__construct($response);

        //recusively create new SforceObject lookUp objects.
        if (isset($this->sobjects)) {
            foreach ($this->sobjects as $i => $subSObject) {
                $this->sobjects[$i] = new $this($subSObject);
            }
        }

        //recursively create new SforceObject for parent to child relationships.
        if (isset($this->queryResult)) {
            foreach ($this->queryResult as $i => $subSObject) {
                $this->queryResult[$i] = new $this($subSObject);
            }
        }

    }

    public function &__get($property) {
        if (isset($this->fields[$property])) {
            return $this->fields[$property];
        }
        else {
            return NULL;
        }
    }

    public function __set($property, $value) {
        if (in_array($property, array('Id', 'type'))) {
            $this->$property = $value;
        } 
        else if (isset($value)) {
            if (is_null($this->fields)) {
                $this->fields = array($property => $value);
            }
            else {
                $this->fields[$property] = $value;
            }
            if (isset($this->fieldsToNull[$property])) unset($this->fieldsToNull[$property]);
        }
        else {
            if (is_null($this->fieldsToNull)) {
                $this->fieldsToNull = array($property => $property);
            }
            else {
                $this->fieldsToNull[$property] = $property;
            }
            if (isset($this->fields[$property])) unset($this->fields[$property]);
        }
    }

    public function __isset($property) {
        return isset($this->fields[$property]);
    }

    public function getChildObjects($type) {
        $childObjects = array();
        if (isset($this->queryResult)) {
            foreach ($this->queryResult as $childObject) {
                if ($childObject->type == $type) {
                    $childObjects[] =& $childObject; 
                }
            }
        }
        return $records;
    }

    public function getLookUpObject($type) {
        $lookUpObject = null;
        if (isset($this->sobjects)) {
            foreach ($this->sobjects as $sobject) {
                if ($sobject->type == $type) {
                    $lookUpObject =& $sobject;
                    break;
                }
            }
        }
        return $lookUpObject;
    }

}

class SforceEnvironment {

    const SANDBOX    = 'Sandbox';
    const PRODUCTION = 'Production';

    private static $endPoints = array(
        self::SANDBOX       =>  "https://test.salesforce.com/services/Soap/u/20.0",
        self::PRODUCTION    =>  "https://login.salesforce.com/services/Soap/u/20.0",
    );

    public static function getEndPoint($environment) {
        if (isset(self::$endPoints[$environment])) {
            return self::$endPoints[$environment];
        }
        else {
            throw new Exception("Environment ({$environment}) not supported.");
        }
    }

}

class SforceClient 
    extends SforcePartnerClient {

    const PARTNER_WSDL = "salesforce/partner.wsdl.xml";
    const LIMIT_BATCH_SIZE = 200;

    public function __construct($environment = SforceEnvironment::PRODUCTION, $proxy = null, $soapOptions = array()) {
        parent::__construct();
        $this->createConnection(dirname(__FILE__) . '/' . self::PARTNER_WSDL, $proxy, $soapOptions);
        $this->setEndPoint(SforceEnvironment::getEndPoint($environment));
    }

    public function login($username, $password, $token) {
        return parent::login($username, $password . $token);
    }

    public function query($query, $queryOptions = NULL) {
        if ($queryOptions != NULL) {
            $this->setQueryOptions($queryOptions);
        }

        $resultQuery = parent::query($query);
        $records = array();
        if ($resultQuery->size > 0) {
            $records = array_merge($records, $resultQuery->records);
            while (!$resultQuery->done) {
                $resultQuery = parent::queryMore($resultQuery->queryLocator);
                if ($resultQuery->size > 0) {
                    $records = array_merge($records, $resultQuery->records);
                }
            }
        }

        //Converts SObject into SforceObject
        foreach ($records as $i => $record) {
            $records[$i] = new SforceObject($record);
        }
        return $records;
    }

    public function create($sObjects) {
        return $this->batchProcess('create', $sObjects);
    }

    public function update($sObjects) {
        return $this->batchProcess('update', $sObjects);
    }

    public function delete($sObjects) {
        return $this->batchProcess('delete', $sObjects);
    }

    private function batchProcess($functionName, $sObjects) {
        $results = array();
        foreach (array_chunk($sObjects, self::LIMIT_BATCH_SIZE) as $tmpSObjects) {
            $tmpResults = parent::$functionName($tmpSObjects);
            $results = array_merge($results, $tmpResults);
        }
        return $results;
    }

    public static function toDate($time) {
        return date('Y-m-d', $time); 
    }

    public static function toDateTime($time) {
        return date('c', $time);
    }

    public function getFullQueryString($objectType, $queryLookUpObjectName = true) {
        $metadata = $this->describeSObject($objectType);
        $fields = array();
        foreach ($metadata->fields as $field) {
            if (isset($field->relationshipName) && $queryLookUpObjectName) {
                $fields[] = $field->relationshipName . '.Name';
            }
            $fields = $field->name;
        }

        $query = "Select " . implode(",", $fields) . " From {$objectType}";

        return $query;
    }

}