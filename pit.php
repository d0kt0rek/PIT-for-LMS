<?php

/**
 * Klasa przetwarzajaca identyfikator adresu na dane TERYT (terc, simc, ulic)
 * UWAGA, dane bazy TERYT musza byc wprowadzone w adresie
 **/
class Teryt {
    private $LMS;
    private $DB;

    public function __construct($LMS, $DB) {
	$this->LMS = $LMS;
        $this->DB = $DB;
    }

    private function getStateData($id) {
        $state = $this->DB->GetRow('select ident,name from location_states where id=' . (int)$id);
        return $state;
    }

    private function getCityData($id) {
        $city = $this->DB->GetRow('select * from location_cities where id=' . (int)$id);
        return $city;
    }

    private function getBoroughData($id) {
        $borough = $this->DB->GetRow('select * from location_boroughs where id=' . (int)$id);
        return $borough;
    }

    private function getDistrictData($id) {
        $district = $this->DB->GetRow('select * from location_districts where id=' . (int)$id);
        return $district;
    }

    private function getTerc($data) {
        $terc = $data['state']['ident'] . $data['district']['ident'] . $data['borough']['ident'] . $data['borough']['type'];
        return $terc;
    }

    private function getStreetData($id) {
        $street = $this->DB->GetRow('select * from location_streets where id=' . (int)$id);
        return $street;
    }

    public function getTerytDataFromAddress($address) {
        $teryt = array();
        $state = $this->getStateData($address['location_state']); //wojewodztwo
        $city  = $this->getCityData($address['location_city']); // miasto
        $borough  = $this->getBoroughData($city['boroughid']); //gmina
        $district = $this->getDistrictData($borough['districtid']); //powiat
        if ($address['location_state'] != $district['stateid']) {
            throw new Exception('TERYT customer data verification failed (addresID=' . $address['address_id'] . ' customer_address_id=' . $address['customer_address_id'] . '. Full location string=' . $address['location'] . ')');
        }
        $teryt['terc'] = $this->getTerc(compact('state', 'borough', 'district'));
        $teryt['simc'] = $city['ident'];
        if ($address['location_street'] != NULL) {
            $street = $this->getStreetData($address['location_street']);
            $teryt['ulic'] = $street['ident'];
        } else {
            $teryt['ulic'] = NULL;
        }
        $teryt['porzadkowy'] = $address['location_house'];
        return $teryt;
    }
};

/**
 * klasa wykorzystywana w przypadku operacji na liscie klientow
 **/
class Customers {
    private $customerList;
    private $LMS;
    private $teryt;

    function __construct($LMS, $teryt) {
        $this->LMS = $LMS;
        $this->teryt = $teryt;
        $this->getCustomerList();
    }

    private function getCustomerList() {
        // argumenty wziete z modulu customerlist
        $customers = array();
        $args=array(
            "flags" => array(),
            "page" => 1,
            "state" => "3", // podlaczeni
            "network" => "0",
            "customergroup" => array(),
            "nodegroup" => "",
            "division" => "1",
            "assignments" => "0",
            "search" => array(),
            "sqlskey" => "AND"
       );

       $this->customerList = $this->LMS->GetCustomerList($args);
    }

    public function getCustomersTeryt() {

        foreach ($this->customerList as $customer) {
            $addresses = $this->LMS->getCustomerAddresses($customer['id'], true);
            foreach ($addresses as $addres) {
                if ($addres['teryt'] == 1) {
                    $terytData = $this->teryt->getTerytDataFromAddress($addres);
                    var_dump($terytData);
                }
            }
        }
    }
}

/**
 * klasa wykorzystywana w przypadku operacjach na liscie klientow
 **/
class Nodes {
    private $nodesList;
    private $LMS;
    private $DB;
    private $linkMap;


    function __construct($LMS, $DB) {
        $this->LMS = $LMS;
        $this->DB = $DB;
        $this->getNodesList();
        $this->linkMap = array();
    }

    private function getNodesList() {
        $search = array(
            'status' => 0, // zamkniete
            'type' => -1, //wszystkie
            'invprojectid' => -1, //wszystkie
            'ownership' => -1, //wszystkie
            'divisionid' => -1, //wszystkie
        );
        $this->nodesList = $this->LMS->GetNetNodeList($search,'id');
    }

    // pobiera podlaczone do wezla urzadzenia
    // zapytanie wziete z modulu netnodeinfo
    private function getHubDevices($id) {
        $netdevlist = $this->DB->GetAll(
            'SELECT d.*, addr.location,
                lb.name AS borough_name, lb.type AS borough_type, lb.ident AS borough_ident,
                ld.name AS district_name, ld.ident AS district_ident,
                ls.name AS state_name, ls.ident AS state_ident
            FROM netdevices d
            LEFT JOIN vaddresses addr       ON d.address_id = addr.id
            LEFT JOIN location_streets lst  ON lst.id = addr.street_id
            LEFT JOIN location_cities lc    ON lc.id = addr.city_id
            LEFT JOIN location_boroughs lb  ON lb.id = lc.boroughid
            LEFT JOIN location_districts ld ON ld.id = lb.districtid
            LEFT JOIN location_states ls    ON ls.id = ld.stateid
            WHERE d.netnodeid = ?
            ORDER BY name',
            array(
                $id
            )
        );
        return $netdevlist;
    }

    public function generateCSVFromNodes($splitDevicesToMultipleHubs = false) {
        // print header
        echo "we01_id_wezla,we02_tytul_do_wezla,we03_id_podmiotu_obcego,we04_terc,we05_simc,we06_ulic,we07_nr_porzadkowy,we08_szerokosc,we09_dlugosc,we10_medium_transmisyjne,we11_bsa,we12_technologia_dostepowa,we13_uslugi_transmisji_danych,we14_mozliwosc_zwiekszenia_liczby_interfejsow,we15_finansowanie_publ,we16_numery_projektow_publ,we17_infrastruktura_o_duzym_znaczeniu,we18_typ_interfejsu,we19_udostepnianie_ethernet\n";
        foreach ($this->nodesList as $node)
        {
            // bierzemy pod uwage tylko urzadenia posiadajace '[PIT]' w nazwie
            if (strstr($node['name'],'[PIT]') !== false) {
                $name = substr($node['name'], 5);
                if ($splitDevicesToMultipleHubs == true) {
                    $netdevlist = $this->getHubDevices($node['id']);
                    foreach ($netdevlist as $netdev) {
                        echo 'WW_' . $name. '_' . $netdev['id'] .
                             ',Węzeł własny,' .
                             ',' .
                             $node['terc'] . ',' .
                             $node['simc'] . ',' .
                             $node['ulic'] . ',' .
                             $node['location_house'] . ',' .
                             $node['latitude'] . ',' .
                             $node['longitude'] . ',' .
                             'radiowe,' .
                             'Nie,' .
                             'WiFi – 802.11a w paśmie 5GHz,' . //UWAGA TO NIE JEST ZWYKLY "-", w przypadku edycji na inne pola, nie kasowac tego znaku
                             ',' .
                             'Nie,' .
                             'Nie,' .
                             ',' .
                             'Nie,' .
                             ",\n" ;
                    }
                } else {
                    echo 'WW_' . $name .
                         ',Węzeł własny,' .
                         ',' .
                         $node['terc'] . ',' .
                         $node['simc'] . ',' .
                         $node['ulic'] . ',' .
                         $node['location_house'] . ',' .
                         $node['latitude'] . ',' .
                         $node['longitude'] . ',' .
                         'radiowe,' .
                         'Nie,' .
                         'WiFi – 802.11a w paśmie 5GHz,' . //UWAGA TO NIE JEST ZWYKLY "-", w przypadku edycji na inne pola, nie kasowac tego znaku
                         ',' .
                         'Nie,' .
                         'Nie,' .
                         ',' .
                         'Nie,' .
                         ",\n" ;
                }
            }
        }
    }

    /**
     * Generuje punkty elastycznosci bazujac na wprowadzonych wezlach (kazdy wezel ma swoj PE)
     * lub bazujac na urzadzeniach podlaczonych do wezla (kazde urzadzenie ma swoj PE)
     **/
    public function generateEPCSVFromNodes($splitDevicesToMultipleHubs = false) {
        // print header
        echo "pe01_id_pe,pe02_typ_pe,pe03_id_wezla,pe04_pdu,pe05_terc,pe06_simc,pe07_ulic,pe08_nr_porzadkowy,pe09_szerokosc,pe10_dlugosc,pe11_medium_transmisyjne,pe12_technologia_dostepowa,pe13_mozliwosc_swiadczenia_uslug,pe14_finansowanie_publ,pe15_numery_projektow_publ\n";
        foreach ($this->nodesList as $node)
        {
            // bierzemy pod uwage tylko urzadenia posiadajace '[PIT]' w nazwie
            if (strstr($node['name'],'[PIT]') !== false) {
                $name = substr($node['name'], 5);
                if ($splitDevicesToMultipleHubs == true) {
                    $netdevlist = $this->getHubDevices($node['id']);
                    foreach ($netdevlist as $netdev) {
                        echo 'PE_' . $name. '_' . $netdev['id'] . ',' .
                             '11,' . //maszt telekomunikacyjny
                             'WW_' . $name . '_' . $netdev['id'] . ',' .
                             'Tak,' .
                             $node['terc'] . ',' .
                             $node['simc'] . ',' .
                             $node['ulic'] . ',' .
                             $node['location_house'] . ',' .
                             $node['latitude'] . ',' .
                             $node['longitude'] . ',' .
                             'radiowe,' .
                             'WiFi – 802.11a w paśmie 5GHz,' . //UWAGA TO NIE JEST ZWYKLY "-", w przypadku edycji na inne pola, nie kasowac tego znaku
                             '09,' . //usluga swiadczona dla uzytkownikow koncowych
                             'Nie,' .
                             'Nie,' . "\n";
                    }
                } else {
                    echo 'PE_' . $name . ',' .
                         '11,' . //maszt telekomunikacyjny
                         'WW_' . $name . ',' .
                         'Tak,' .
                         $node['terc'] . ',' .
                         $node['simc'] . ',' .
                         $node['ulic'] . ',' .
                         $node['location_house'] . ',' .
                         $node['latitude'] . ',' .
                         $node['longitude'] . ',' .
                         'radiowe,' .
                         'WiFi – 802.11a w paśmie 5GHz,' . //UWAGA TO NIE JEST ZWYKLY "-", w przypadku edycji na inne pola, nie kasowac tego znaku
                         '09,' . //usluga swiadczona dla uzytkownikow koncwych
                         'Nie,' .
                         'Nie,' . "\n";
                }
            }
        }
    }

    private function addSideToLinkMap($hub, $dev, $theOtherDev) {
        $found = false;
        foreach ($this->linkMap as $id => $entry) {
            if ((($entry['src'] == $dev['id']) && ($entry['dst'] == $theOtherDev['id'])) ||
                (($entry['dst'] == $dev['id']) && ($entry['src'] == $theOtherDev['id']))) {
                $found = true;
                if (!isset($entry['dstHubId']) && isset($entry['srcHubId']) && $entry['srcHubId'] !== $hub['id']) {
                    $this->linkMap[$id]['dstHubId'] = $hub['id'];
                    $this->linkMap[$id]['dstHubName'] = $hub['name'];
                    return;
                } else if (!isset($entry['dstHubId']) && isset($entry['srcHubId']) && $entry['srcHubId'] === $hub['id']) {
                    // polaczenie wewnatrz jednego huba nie jest linia bezprzewodowa
                    return;
                }
            }
        }
        if ($found) {
            // nie powinno dojsc do tej sytuacji
            throw new Exception('Nieprawidlowe dane przy dodawaniu polaczen' . var_export(array($this->linkMap, $hub['id'], $dev['id'], $theOtherDev['id'])));
        }
        $this->linkMap[] = array('src' => $dev['id'], 'dst' => $theOtherDev['id'], 'srcHubId' => $hub['id'], 'srcHubName' => $hub['name']);
    }

    /**
     * generuje plik połączen bezprzewodowych (CSV) pomiędzy węzłami oznaczonymi tagiem [PIT]
     * linki pomiędzy węzłami identyfikuje na podstawie transliterowanego "Dosył_"
     **/
    public function generateWirelessLinesCSV() {
        //print header
        echo '"lb01_id_lb","lb02_id_punktu_poczatkowego","lb03_id_punktu_koncowego","lb04_medium_transmisyjne","lb05_nr_pozwolenia_radiowego","lb06_pasmo_radiowe","lb07_system_transmisyjny","lb08_przepustowosc","lb09_mozliwosc_udostepniania"' . "\n";
        foreach ($this->nodesList as $node)
        {
            if (strstr($node['name'],'[PIT]') !== false) {
                $name = mb_substr($node['name'], 5);
                $netdevlist = $this->getHubDevices($node['id']);
                foreach ($netdevlist as $subDev) {
                    if (($res = mb_strpos(mb_strtolower(iconv("UTF-8", "ASCII//TRANSLIT", $subDev['name'])), "dosyl_")) !== false) {
                        // subDev to "dosyl_"
                        $subDevName = mb_substr($subDev['name'], $res);
                        $netdevconnected = $this->LMS->GetNetDevConnectedNames($subDev['id']);
                        $theOtherSide = false;
                        $uplinksCount = 0;
                        foreach ($netdevconnected as $connectedDev) {
                            if (($res = mb_strpos(mb_strtolower(iconv("UTF-8", "ASCII//TRANSLIT", $connectedDev['name'])), "dosyl_")) !== false) {
                                // polaczone urzadzenie to tez "dosyl_"
                                $otherSideInfo = $this->LMS->getNetDev($connectedDev['id']);
                                if ($otherSideInfo['netnodeid'] == $node['id']) {
                                    // nie robimy placzen w ramach jednego wezla
                                    continue;
                                }
                                $uplinksCount++;
                                $theOtherSide = $connectedDev;
                                $this->addSideToLinkMap($node, $subDev, $theOtherSide);
                            }
                        }
                    }
                }
            }
        }
        $channels = array('5.1','5.2','5.5','5.6');
        foreach ($this->linkMap as $link) {
            echo '"LB_' . $link['srcHubId'] . '_' . $link['dstHubId'] . '","WW_' . mb_substr($link['srcHubName'],5) . '","WW_' . mb_substr($link['dstHubName'],5) . '","radiowe na częstotliwości ogólnodostępnej","Radiolinia",' . $channels[rand(0,3)] . ',"13","Nie"' . "\n";
        }
    }
}

function displayMenu() {
    echo "<html><head><title>PIT-Report</title></head><body>";
    echo "<a href='?m=pit&mode=hubsDivided'>Węzły z rozbiciem na osobne węzeł dla każdego urządzenia składającego się na węzeł [Obsolete]</a><br>";
    echo "<a href='?m=pit&mode=epDivided'>Punkty elastyczności dla węzłów rozbiciem na osobne węzły dla każdego urządzenia składającego się na węzeł [Obsolete</a><br>";
    echo "<br><br><br>";
    echo "<a href='?m=pit&mode=hubs'>Węzły bez rozbicia na zawarte w nich urządzenia [Preferwane... Na ten moment]</a><br>";
    echo "<a href='?m=pit&mode=ep'>Punkty elastyczności dla węzłów bez rozbicia na zawarte w nich urządzenia [Preferwane... Na ten moment]</a><br>";
    echo "<br><br><br>";
    echo "<a href='?m=pit&mode=wl'>Linie bezprzewodowe [Preferwane... Na ten moment]</a><br>";
    echo "</body></html>";
}

$nodesList = new Nodes($LMS, $DB);

if (!empty($_GET['mode'])) {
    switch ($_GET['mode']) {
        case 'hubsDivided':
            $nodesList->generateCSVFromNodes(true);
            break;
        case 'epDivided':
            $nodesList->generateEPCSVFromNodes(true);
            break;
        case 'hubs':
            $nodesList->generateCSVFromNodes();
            break;
        case 'ep':
            $nodesList->generateEPCSVFromNodes();
            break;
        case 'wl':
            $nodesList->generateWirelessLinesCSV();
            break;
        default:
            displayMenu();
    }
} else {
    displayMenu();
}



