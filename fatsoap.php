<?php
/*
 * I hate SOAP, it should be COAP.
 *
 * These classes are the result of working with a very strict, broken
 * WSDL, web service.
 */

class SOAP_Client {
	public $namespaces = array('soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/');
	public $xml;
	public $writer;

	/*
	 * create_xml - builds the XML object for the request
	 *
	 * params:
	 * $body - an XMLObject that will be used as the body
	 * $header - if null no headers are added, else should be an object that extends XMLObject
	 */
	public function create_xml($body, $header = null) {
		$soapns = $this->namespace_by_url('http://schemas.xmlsoap.org/soap/envelope/');
		if (!isset($soapns)) {
			throw new Exception("You need a namespace for the SOAP envelope");
		}

		$this->writer = new XMLWriter();
		$this->writer->openMemory();
		$this->writer->startDocument('1.0', 'UTF-8');
		$this->writer->setIndent(4);

		$this->writer->startElement("$soapns:Envelope");
		foreach ($this->namespaces as $key => $url) {
			$this->writer->writeAttribute("xmlns:$key", $url);
		}

		if ($header !== null) {
			$this->writer->startElement("$soapns:Header");
			$header->set_client(&$this);
			$header->create_xml();
			$this->writer->endElement(); // Header
		}

		$this->writer->startElement("$soapns:Body");
		$body->set_client(&$this);
		$body->create_xml();
		$this->writer->endElement(); // Body

		$this->writer->endElement(); // Envelope
	}

	public function namespace_by_url($target) {
		foreach ($this->namespaces as $key => $url) {
			if ($url == $target) {
				return $key;
			}
		}
	}

}

/*
 * XMLObject is a class that should be inherited by any object
 * that will be used with SOAP_Client. It's job is to provide a
 * consistent serialization with maximum flexibility
 */
class XMLObject {
	public $namespace = null;

	/*
	 * soap_client & writer will hold references to the client & writer
	 */
	private $soap_client = null;
	private $writer = null;

	/*
	 * set_client associates the client & writer with this object
	 */
	public function set_client(&$client) {
		$client_cls = get_class($client);
		if ($client_cls == 'SOAP_Client') {
			$this->soap_client =& $client;
			$this->writer =& $client->writer;
			return true;
		}
		return false;
	}

	/*
	 * with_namespace is a utility function to prefix the namespace onto
	 * a value, if it's not null
	 */
	private function with_namespace($name) {
		if ($this->namespace !== null) {
			/*
			 * allow name spaces to be specified by URL too
			 */
			$ns = $this->soap_client->namespace_by_url($this->namespace);
			if (isset($ns)) {
				$this->namespace = $ns;
			}

			if (array_key_exists($this->namespace, $this->soap_client->namespaces)) {
				$name = $this->namespace . ":" . $name;
			} else {
				throw new Exception("You have specified an invalid name space (" . $this->namespace . ") for the " . get_class($this) . " class");
			}
		}
		return $name;
	}

	/*
	 * serialize_writer is the new version of serialize that uses the
	 * XMLWriter class
	 */
	private function serialize_writer($name, $val) {
		if (is_array($val)) {
			$this->writer->startElement($this->with_namespace($name));
			foreach ($val as $sub_key => $sub_val) {
				$this->serialize_writer($sub_key, $sub_val);
			}
			$this->writer->endElement();
		} elseif (is_object($val)) {
			$cls = get_class($val);
			$ref = new ReflectionClass($cls);
			$parent = $ref->getParentClass();
			if ($parent->name == "XMLObject") {
				if (preg_match("#^[0-9]$#", $name)) {
					/* if the name is all numeric, ie not an explicitly associatiave
					 * array, then we use the class name in its place. this is probably
					 * a terrible hack, but it works...
					 */
					$name = $cls;
				}
				$val->set_client(&$this->soap_client);
				$this->writer->startElement($this->with_namespace($name));
				$val->create_xml();
				$this->writer->endElement();
			} else {
				throw new Exception("serialize_writer got an object I can't serialize: $cls");
			}
		} else {
			$this->writer->writeElement($this->with_namespace($name), $val);
		}
	}

	/*
	 * create_xml is the new version of the object serializtion logic
	 * that now uses XMLWriter instead of simplexml.
	 * it's job is to inspect itself through reflection and then
	 * serialize any properties recursively
	 */
	public function create_xml() {
		$cls = get_class($this);

		$this->writer->startElement($this->with_namespace($cls));

		$ref = new ReflectionClass($cls);
		$props = $ref->getProperties();
		foreach ($props as $prop) {
			$name = $prop->getName();
			if ($name == 'namespace') { 
				continue;
			}
			if (isset($this->{$name})) {
				$val = $this->{$name};
				$this->serialize_writer($name, $val);
			}
		}
		$this->writer->endElement();
	}
}
