<?php
function collect_osint($config) {
    $class  = 'Botnet infection';
    $source = 'Bambenek';
    $type   = 'ABUSE';

    $baseurl = 'http://osint.bambenekconsulting.com/feeds/';

    $feeds = array(
                    'Banjori'               => 'banjori-asn.txt',
                    'Bebloh/URLZone'        => 'bebloh-asn.txt',
    //                'Cryptolocker'          => 'cryptolocker-asn.txt',
                    'Cryptowall'            => 'cryptowall-asn.txt',
                    'Dyre'                  => 'dyre-asn.txt',
                    'Geodo'                 => 'geodo-asn.txt',
                    'Hesperbot'             => 'hesperbot-asn.txt',
                    'Matsnu'                => 'matsnu-asn.txt',
                    'Necurs'                => 'necurs-asn.txt',
                    'P2P GOZ'               => 'p2pgoz-asn.txt',
                    'Pushdo'                => 'pushdo-asn.txt',
                    'Qakbot'                => 'qakbot-asn.txt',
                    'Ramnit'                => 'ramnit-asn.txt',
                    'Symmi'                 => 'symmi-asn.txt',
                    'Tinba / TinyBanker'    => 'tinba-asn.txt',
                  );

    $fieldnames = array(
                        0 => 'asn',
                        1 => 'ip',
                        2 => 'netblock',
                        3 => 'country',
                        4 => 'rir',
                        5 => 'assigned-date',
                        6 => 'description',
                       );
    
    foreach($feeds as $name => $uri) {

        // Collect feeddata and unset first and last fields which are bogus
        $feeddata = explode("\n", file_get_contents($baseurl . $uri));
        unset($feeddata[0]);
        unset($feeddata[count($feeddata)]);

        // Only continue if there is actually some data in the feed results
        if(count($feeddata) !== 0) {
            foreach($feeddata as $id => $row) {
                $fielddata = explode(' | ', $row);
                $fields    = array_combine($fieldnames, $fielddata);

                // Pending confirmation about field layout from Bambenek
                $outReport['ip']            = '';
                $outReport['source']        = '';
                $outReport['type']          = '';
                $outReport['class']         = '';
                $outReport['information']   = array();
                $outReport['timestamp']     = '';
        
                print_r($fields);
            }
        }
    }

    logger(LOG_INFO, __FUNCTION__ . " Completed message ");
    return true;
}
?>