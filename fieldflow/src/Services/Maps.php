<?php
namespace RoutesPro\Services;

use RoutesPro\Admin\Settings;

interface MapsProviderInterface {
    public function geocode($address);
    public function distanceMatrix($origins, $destinations);
    public function directions($origin, $destination, $waypoints=[]);
}

class GoogleMapsProvider implements MapsProviderInterface {
    protected $key;
    public function __construct(){ $this->key = Settings::get('google_maps_key'); }

    public function geocode($address){
        $url = add_query_arg([ 'address'=>$address, 'key'=>$this->key ], 'https://maps.googleapis.com/maps/api/geocode/json');
        $res = wp_remote_get($url, ['timeout'=>20]);
        if (is_wp_error($res)) return null;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!empty($body['results'][0]['geometry']['location'])) {
            $loc = $body['results'][0]['geometry']['location'];
            return ['lat'=>$loc['lat'],'lng'=>$loc['lng'],'raw'=>$body['results'][0]];
        }
        return null;
    }
    public function distanceMatrix($origins, $destinations){
        $o = implode('|', array_map(fn($p)=>$p['lat'].','.$p['lng'], $origins));
        $d = implode('|', array_map(fn($p)=>$p['lat'].','.$p['lng'], $destinations));
        $url = add_query_arg([ 'origins'=>$o, 'destinations'=>$d, 'key'=>$this->key, 'units'=>'metric' ], 'https://maps.googleapis.com/maps/api/distancematrix/json');
        $res = wp_remote_get($url, ['timeout'=>25]); if (is_wp_error($res)) return null;
        return json_decode(wp_remote_retrieve_body($res), true);
    }
    public function directions($origin, $destination, $waypoints=[]){
        $params = [
            'origin'=>$origin['lat'].','.$origin['lng'],
            'destination'=>$destination['lat'].','.$destination['lng'],
            'key'=>$this->key
        ];
        if ($waypoints) $params['waypoints'] = 'optimize:true|' . implode('|', array_map(fn($p)=>$p['lat'].','.$p['lng'], $waypoints));
        $url = add_query_arg($params, 'https://maps.googleapis.com/maps/api/directions/json');
        $res = wp_remote_get($url, ['timeout'=>30]); if (is_wp_error($res)) return null;
        return json_decode(wp_remote_retrieve_body($res), true);
    }
}

class AzureMapsProvider implements MapsProviderInterface {
    protected $key;
    public function __construct(){ $this->key = Settings::get('azure_maps_key'); }

    public function geocode($address){
        $url = add_query_arg([ 'api-version'=>'1.0', 'query'=>$address, 'subscription-key'=>$this->key ], 'https://atlas.microsoft.com/search/address/json');
        $res = wp_remote_get($url, ['timeout'=>20]); if (is_wp_error($res)) return null;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!empty($body['results'][0]['position'])) {
            $pos = $body['results'][0]['position'];
            return ['lat'=>$pos['lat'],'lng'=>$pos['lon'],'raw'=>$body['results'][0]];
        }
        return null;
    }
    public function distanceMatrix($origins, $destinations){
        $payload = [
            'origins'=> array_map(fn($p)=>['latitude'=>$p['lat'],'longitude'=>$p['lng']], $origins),
            'destinations'=> array_map(fn($p)=>['latitude'=>$p['lat'],'longitude'=>$p['lng']], $destinations),
            'travelMode'=>'driving'
        ];
        $res = wp_remote_post('https://atlas.microsoft.com/route/matrix/json?api-version=1.0&subscription-key='.$this->key, [
            'headers'=>['Content-Type'=>'application/json'],
            'body'=> wp_json_encode($payload), 'timeout'=>30
        ]);
        if (is_wp_error($res)) return null;
        return json_decode(wp_remote_retrieve_body($res), true);
    }
    public function directions($origin, $destination, $waypoints=[]){
        $query = $origin['lat'].','.$origin['lng'].':'.$destination['lat'].','.$destination['lng'];
        foreach ($waypoints as $w) { $query .= ':'.$w['lat'].','.$w['lng']; }
        $url = 'https://atlas.microsoft.com/route/directions/json?api-version=1.0&subscription-key='.$this->key.'&query='.$query.'&travelMode=car';
        $res = wp_remote_get($url, ['timeout'=>30]); if (is_wp_error($res)) return null;
        return json_decode(wp_remote_retrieve_body($res), true);
    }
}

class MapsFactory {
    public static function make(): ?MapsProviderInterface {
        $prov = Settings::get('maps_provider','leaflet');
        if ($prov === 'google') return new GoogleMapsProvider();
        if ($prov === 'azure')  return new AzureMapsProvider();
        return null;
    }
}
