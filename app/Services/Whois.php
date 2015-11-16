<?php

namespace App\Services;

use App\Helpers\IpUtils;

class Whois
{
    private $whoisUrl = "http://185.42.223.50/whois.php";
    private $rir;
    private $allocationData;
    private $input;
    private $rawData;
    private $rawLines;
    private $ipUtils;

    private $ignoreEmailAddresses = [
            ];

    private $ignoreEmailDomains = [
                'apnic.net',
                'afrinic.net',
                'lacnic.net',
                'ripe.net',
                'arin.net',
                'arin.asn',
                'arin.org',

                'example.com',
                'cert.br',
                'arin.poc',
                'supplied.unknown',
            ];

    public function __construct($input, $cidr = null)
    {
        $this->ipUtils = new IpUtils;
        $this->input = $this->ipUtils->normalizeInput(trim($input));
        $allocation = $this->ipUtils->getAllocationEntry($this->input, $cidr);

        // Lets make sure we found an allocation first
        if (is_null($allocation) !== true) {
            $this->rir = $allocation->rir;
            $this->allocationData = $allocation;
            // Lets fetch the raw whois data
            $this->rawData = $this->getRawWhois();
            $this->rawLines = explode("\n", $this->rawData);
        } else {
            $this->rawData = null;
            $this->rawLines = [];
        }

    }

    private function getRawWhois()
    {
        $url = $this->whoisUrl . "?input=" . $this->input . "&whois_server=" . $this->rir->whois_server;
        return  file_get_contents($url);
    }

    public function parse()
    {
        $functionName = strtolower($this->rir->name) . "Execute";
        return $this->$functionName();
    }

    public function raw()
    {
        return $this->rawData;
    }

    private function arinExecute()
    {
        $data = new \stdClass();

        // Get All email contacts on object
        $data->emails = $this->extractAllEmails();

        // Extract ARIN Abuse Email
        $abuseEmails = $this->extractValues('OrgAbuseEmail');
        if (is_array($abuseEmails) === true) {
            $data->abuse_emails = $abuseEmails;
        } else {
            $data->abuse_emails[] = $abuseEmails;
        }
        foreach ($data->emails as $email) {
            if (stristr($email, "abuse")) {
                $data->abuse_emails[] = $email;
            }
        }
        $data->abuse_emails = $this->cleanUpEmails($data->abuse_emails);


        // Get description (Organization)
        if ($this->ipUtils->getInputType($this->input) === 'asn') {
            $orgs = $this->extractValues('OrgName');
            if (is_array($orgs) === true) {
                $orgs = end($orgs);
            }
            $data->description = [$orgs];
        } else {
            $orgs = $this->extractValues('Organization');
            if (is_array($orgs) === true) {
                $orgs = end($orgs);
            }
            // Remove the ARIN OrgID
            $orgParts = explode("(", strrev($orgs), 2);
            $finalOrg = trim(strrev(end($orgParts)));
            $data->description = [$finalOrg];
        }


        // Get network name
        if ($this->ipUtils->getInputType($this->input) === 'asn') {
            $data->name = $this->extractValues('ASName');
        } else {
            $data->name = $this->extractValues('NetName');
        }
        if (is_array($data->name) === true) {
            $data->name = $data->name[0];
        }

        // Get Country
        $counrty = $this->extractValues('country');
        if (is_array($counrty) === true) {
            $data->counrty_code = strtoupper(substr($counrty[0], 0, 2));
        } else if (is_null($counrty) === true) {
            $data->counrty_code = strtoupper($this->allocationData->counrty_code);
        } else {
            $data->counrty_code = strtoupper(substr($counrty, 0, 2));
        }

        // get the owner address
        $data->address = $this->getAddress();

        return $this->cleanEmptyData($data);
    }

    private function ripeExecute()
    {
        $data = new \stdClass();

        // Get All email contacts on object
        $data->emails = $this->extractAllEmails();

        // Extract Ripe Abuse Email
        $data->abuse_emails = [];
        foreach ($this->rawLines as $line) {
            if (strstr($line, "% Abuse contact for")) {
                $parts = explode(' ', $line);
                $data->abuse_emails[] = strtolower(trim(end($parts), '\''));
                unset($parts);
            }
        }

        // Extract generic abuse emails
        $genericAbuseEmails = $this->extractValues('abuse-mailbox');
        if ($genericAbuseEmails !== null) {
            // Make it an array if not already
            if (is_array($genericAbuseEmails) !== true) {
                $genericAbuseEmails = [$genericAbuseEmails];
            }

            $genericAbuseEmails = array_map('strtolower', $genericAbuseEmails);
            $data->abuse_emails = array_unique(array_merge($genericAbuseEmails));
        }
        foreach ($data->emails as $email) {
            if (stristr($email, "abuse")) {
                $data->abuse_emails[] = $email;
            }
        }
        $data->abuse_emails = $this->cleanUpEmails($data->abuse_emails);

        // Get description
        $description = $this->extractValues('descr');
        if (empty($description) === true) {
            $data->description = [];
        } else if (is_array($description) === true) {
            $data->description = $description;
        } else {
            $data->description = [$description];
        }

        // Get network name
        if ($this->ipUtils->getInputType($this->input) === 'asn') {
            $data->name = $this->extractValues('as-name');
            if (is_null($data->name) === true && isset($data->description[0]) === true) {
                $data->name = $data->description[0];
            }
        } else {
            $data->name = $this->extractValues('netname');
        }
        if (is_array($data->name) === true) {
            $data->name = $data->name[0];
        }

        // Get Country
        $counrty = $this->extractValues('country');
        if (is_array($counrty) === true) {
            $data->counrty_code = strtoupper(substr($counrty[0], 0, 2));
        } else if (is_null($counrty) === true) {
            $data->counrty_code = strtoupper($this->allocationData->counrty_code);
        } else {
            $data->counrty_code = strtoupper(substr($counrty, 0, 2));
        }

        // get the owner address
        $data->address = $this->getAddress();

        return $this->cleanEmptyData($data);
    }

    private function afrinicExecute()
    {
        $data = new \stdClass();

        // Get All email contacts on object
        $data->emails = $this->extractAllEmails();

        // Extract Afrinic Abuse Email
        $data->abuse_emails = [];
        foreach ($this->rawLines as $line) {
            if (strstr($line, "% Abuse contact for")) {
                $parts = explode(' ', $line);
                $data->abuse_emails[] = strtolower(trim(end($parts), '\''));
                unset($parts);
            }
        }

        // Extract generic abuse emails
        $genericAbuseEmails = $this->extractValues('abuse-mailbox');
        if ($genericAbuseEmails !== null) {
            // Make it an array if not already
            if (is_array($genericAbuseEmails) !== true) {
                $genericAbuseEmails = [$genericAbuseEmails];
            }

            $genericAbuseEmails = array_map('strtolower', $genericAbuseEmails);
            $data->abuse_emails = array_unique(array_merge($genericAbuseEmails));
        }
        foreach ($data->emails as $email) {
            if (stristr($email, "abuse")) {
                $data->abuse_emails[] = $email;
            }
        }
        $data->abuse_emails = $this->cleanUpEmails($data->abuse_emails);

        // Get description
        $description = $this->extractValues('descr');
        if (empty($description) === true) {
            $data->description = [];
        } else if (is_array($description) === true) {
            $data->description = $description;
        } else {
            $data->description = [$description];
        }

        // Get network name
        if ($this->ipUtils->getInputType($this->input) === 'asn') {
            $data->name = $this->extractValues('as-name');
            if (is_null($data->name) === true && isset($data->description[0]) === true) {
                $data->name = $data->description[0];
            }
        } else {
            $data->name = $this->extractValues('netname');
        }
        if (is_array($data->name) === true) {
            $data->name = $data->name[0];
        }

        // Get Country
        $counrty = $this->extractValues('country');
        if (is_array($counrty) === true) {
            $data->counrty_code = strtoupper(substr($counrty[0], 0, 2));
        } else if (is_null($counrty) === true) {
            $data->counrty_code = strtoupper($this->allocationData->counrty_code);
        } else {
            $data->counrty_code = strtoupper(substr($counrty, 0, 2));
        }

        // get the owner address
        $data->address = $this->getAddress();

        return $this->cleanEmptyData($data);
    }

    private function apnicExecute()
    {
        $data = new \stdClass();

        // Get All email contacts on object
        $data->emails = $this->extractAllEmails();

        // Extract APNIC Abuse Email
        $abuseEmails = $this->extractValues('abuse-mailbox');
        if (is_array($abuseEmails) === true) {
            $data->abuse_emails = $abuseEmails;
        } else {
            $data->abuse_emails[] = $abuseEmails;
        }
        foreach ($data->emails as $email) {
            if (stristr($email, "abuse")) {
                $data->abuse_emails[] = $email;
            }
        }
        $data->abuse_emails = $this->cleanUpEmails($data->abuse_emails);

        // Get description
        $description = $this->extractValues('descr');
        if (empty($description) === true) {
            $data->description = [];
        } else if (is_array($description) === true) {
            $data->description = $description;
        } else {
            $data->description = [$description];
        }

        // Get network name
        if ($this->ipUtils->getInputType($this->input) === 'asn') {
            $data->name = $this->extractValues('as-name');
            if (is_null($data->name) === true && isset($data->description[0]) === true) {
                $data->name = $data->description[0];
            }
        } else {
            $data->name = $this->extractValues('netname');
        }
        if (is_array($data->name) === true) {
            $data->name = $data->name[0];
        }

        // Get Country
        $counrty = $this->extractValues('country');
        if (is_array($counrty) === true) {
            $data->counrty_code = strtoupper(substr($counrty[0], 0, 2));
        } else if (is_null($counrty) === true) {
            $data->counrty_code = strtoupper($this->allocationData->counrty_code);
        } else {
            $data->counrty_code = strtoupper(substr($counrty, 0, 2));
        }


        // get the owner address
        $data->address = $this->getAddress();

        return $this->cleanEmptyData($data);
    }

    private function lacnicExecute()
    {
        $data = new \stdClass();

        // Get All email contacts on object
        $data->emails = $this->extractAllEmails();

        // Extract Lacnic Abuse Email
        $abuseEmails = $this->extractValues('e-mail');
        if (is_array($abuseEmails) === true) {
            $data->abuse_emails = $abuseEmails;
        } else {
            $data->abuse_emails[] = $abuseEmails;
        }
        foreach ($data->emails as $email) {
            if (stristr($email, "abuse")) {
                $data->abuse_emails[] = $email;
            }
        }
        $data->abuse_emails = $this->cleanUpEmails($data->abuse_emails);

        // Get description
        $description = $this->extractValues('owner');
        if (empty($description) === true) {
            $data->description = [];
        } else if (is_array($description) === true) {
            $data->description = $description;
        } else {
            $data->description = [$description];
        }
        $data->description = array_map('utf8_encode', $data->description);

        // No name attribute, lets use the description
        $data->name = $data->description;
        if (is_array($data->name) === true) {
            $data->name = $data->name[0];
        }

        // Get Country
        $counrty = $this->extractValues('country');
        if (is_array($counrty) === true) {
            $data->counrty_code = strtoupper(substr($counrty[0], 0, 2));
        } else if (is_null($counrty) === true) {
            $data->counrty_code = strtoupper($this->allocationData->counrty_code);
        } else {
            $data->counrty_code = strtoupper(substr($counrty, 0, 2));
        }

        // get the owner address
        $data->address = $this->getAddress();

        return $this->cleanEmptyData($data);
    }


    private function extractAllEmails()
    {
        $regex = "/[-.\w]+@[-.\w]+/i";
        preg_match_all($regex, $this->rawData, $matches);

        return $this->cleanUpEmails($matches[0]);

    }

    private function cleanUpEmails($emails)
    {
        $emails = array_map('strtolower', $emails);

        // Remove out ignore emails
        foreach ($emails as $key => $email) {

            // Cleanup any invalid email
            if (empty($email) === true) {
                unset($emails[$key]);
                continue;
            }

            //Remove any email addresses we dont want
            $email = trim(strtolower($email), ".");
            if (in_array($email, $this->ignoreEmailAddresses)) {
                unset($emails[$key]);
                continue;
            }

            // remove domains from emails
            $emailDomain = explode("@", $email)[1];
            foreach($this->ignoreEmailDomains as $ignoreDomain) {
                if ($ignoreDomain === $emailDomain) {
                    unset($emails[$key]);
                    continue;
                }
            }

        }

        return array_unique($emails);
    }

    private function extractValues($key, $last = false)
    {
        $values = [];
        $key = strtolower(trim($key));
        foreach ($this->rawLines as $line) {
            $lineParts = explode(":", $line, 2);
            if (strtolower(trim($lineParts[0])) === $key) {
                $values[] = trim($lineParts[1]);
            }
        }

        if (count($values) === 1) {
            return $values[0];
        }

        if (count($values) > 1) {
            return array_unique($values);
        }

        return null;
    }

    private function cleanEmptyData($data)
    {
        foreach ($data as $key => $value) {

            // Only look at keys that are numbers
            if (is_numeric($key) !== true) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                if (is_array($data)) {
                    $data[$key] = $this->cleanEmptyData($data[$key]);
                } else {
                    $data->$key = $this->cleanEmptyData($data->$key);
                }
            }

            if (is_array($data)) {
                if (empty($data[$key])) {
                    unset($data[$key]);
                }
            } else {
                if (empty($data->$key)) {
                    unset($data->$key);
                }
            }

        }

        return $data;
    }

    private function getAddress()
    {
        $finalAddress = [];

        // APNIC specific
        if ($this->rir->name == "APNIC") {
            $addressParts = explode("address:", $this->raw(), 2);
            $addressParts = explode("\n", end($addressParts));
            foreach($addressParts as $addressPart) {
                if (strstr($addressPart, ":") && !stristr($addressPart, "address:")) {
                    break;
                } else if (stristr($addressPart, "address:")) {
                    $finalAddress[] = trim(explode("address:", $addressPart, 2)[1]);
                } else {
                    $finalAddress[] = trim($addressPart);
                }
            }
        }

        // ARIN specific
        if ($this->rir->name == "ARIN") {
            if ($address = $this->extractValues('Address')) {
                if (is_array($address) === true) {
                    $finalAddress = $address;
                } else {
                    $finalAddress[] = $address;
                }

            }
            if ($address = $this->extractValues('City')) {
                $finalAddress[] = $address;
            }
            if ($address = $this->extractValues('StateProv')) {
                $finalAddress[] = $address;
            }
            if ($address = $this->extractValues('PostalCode')) {
                $finalAddress[] = $address;
            }
            if ($address = $this->extractValues('Country')) {
                $finalAddress[] = $address;
            }

        }

        if ($this->rir->name == "Lacnic" || $this->rir->name == "AfriNIC" || $this->rir->name == "RIPE") {
            $currentKey = false;
            foreach ($this->rawLines as $key => $line) {
                if (!stristr($line, "remarks:") && (strstr($line, "address:") || !stristr($line, ":"))) {
                    if (stristr($line, "address:") && $currentKey === false) {
                        $currentKey = $key;
                        $finalAddress[] = trim(explode("address:", $line, 2)[1]);
                    } else if  (($currentKey + 1) === $key && $currentKey !== false) {
                        if (stristr($line, "address:")) {
                            $finalAddress[] = trim(explode("address:", $line, 2)[1]);
                        } else {
                            $finalAddress[] = trim($line);
                        }
                        $currentKey = $key;
                    }
                }

            }
        }

        return array_unique($finalAddress);
    }



}