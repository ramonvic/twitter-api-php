<?php

/**
 * Twitter-API-PHP : Simple PHP wrapper for the v1.1 API
 * 
 * PHP version 5.3.10
 * 
 * @category Awesomeness
 * @package  Twitter-API-PHP
 * @author   James Mallison <me@j7mbo.co.uk>
 * @license  http://opensource.org/licenses/gpl-license.php GNU Public License
 * @link     http://github.com/j7mbo/twitter-api-php
 */
class TwitterAPIExchange
{
    private $oauth_access_token;
    private $oauth_access_token_secret;
    private $consumer_key;
    private $consumer_secret;
    private $postfields;
    private $getfield;
    protected $oauth;
    public $url;
    
    private $cache_enabled = true;
    private $cache_time = 5;	//Time in minutes
    private $cache_dir = "cache"; //Cache folder

    /**
     * Create the API access object. Requires an array of settings::
     * oauth access token, oauth access token secret, consumer key, consumer secret
     * These are all available by creating your own application on dev.twitter.com
     * Requires the cURL library
     * 
     * @param array $settings
     */
    public function __construct(array $settings)
    {
        if (!in_array('curl', get_loaded_extensions())) 
        {
            throw new Exception('You need to install cURL, see: http://curl.haxx.se/docs/install.html');
        }
        
        if (!isset($settings['oauth_access_token'])
            || !isset($settings['oauth_access_token_secret'])
            || !isset($settings['consumer_key'])
            || !isset($settings['consumer_secret']))
        {
            throw new Exception('Make sure you are passing in the correct parameters');
        }

        $this->oauth_access_token = $settings['oauth_access_token'];
        $this->oauth_access_token_secret = $settings['oauth_access_token_secret'];
        $this->consumer_key = $settings['consumer_key'];
        $this->consumer_secret = $settings['consumer_secret'];

        if (isset($settings['cache_enabled'])){
        	$this->cache_enabled = (boolean)$settings['cache_enabled'];
        }
        
        if (isset($settings['cache_time'])){
        	$this->cache_time = (int)$settings['cache_time'];
        }
        
        if (isset($settings['cache_dir'])){
        	$this->cache_dir = (string)$settings['cache_dir'];
        }
        
        if ($this->cache_enabled) {
        	$this->cache_dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . $this->cache_dir . DIRECTORY_SEPARATOR;
        }
    }
    
    /**
     * Set postfields array, example: array('screen_name' => 'J7mbo')
     * 
     * @param array $array Array of parameters to send to API
     * 
     * @return TwitterAPIExchange Instance of self for method chaining
     */
    public function setPostfields(array $array)
    {
        if (!is_null($this->getGetfield())) 
        { 
            throw new Exception('You can only choose get OR post fields.'); 
        }
        
        if (isset($array['status']) && substr($array['status'], 0, 1) === '@')
        {
            $array['status'] = sprintf("\0%s", $array['status']);
        }
        
        $this->postfields = $array;
        
        return $this;
    }
    
    /**
     * Set getfield string, example: '?screen_name=J7mbo'
     * 
     * @param string $string Get key and value pairs as string
     * 
     * @return \TwitterAPIExchange Instance of self for method chaining
     */
    public function setGetfield($string)
    {
        if (!is_null($this->getPostfields())) 
        { 
            throw new Exception('You can only choose get OR post fields.'); 
        }
        
        $search = array('#', ',', '+', ':');
        $replace = array('%23', '%2C', '%2B', '%3A');
        $string = str_replace($search, $replace, $string);  
        
        $this->getfield = $string;
        
        return $this;
    }
    
    /**
     * Get getfield string (simple getter)
     * 
     * @return string $this->getfields
     */
    public function getGetfield()
    {
        return $this->getfield;
    }
    
    /**
     * Get postfields array (simple getter)
     * 
     * @return array $this->postfields
     */
    public function getPostfields()
    {
        return $this->postfields;
    }
    
    /**
     * Build the Oauth object using params set in construct and additionals
     * passed to this method. For v1.1, see: https://dev.twitter.com/docs/api/1.1
     * 
     * @param string $url The API url to use. Example: https://api.twitter.com/1.1/search/tweets.json
     * @param string $requestMethod Either POST or GET
     * @return \TwitterAPIExchange Instance of self for method chaining
     */
    public function buildOauth($url, $requestMethod)
    {
        if (!in_array(strtolower($requestMethod), array('post', 'get')))
        {
            throw new Exception('Request method must be either POST or GET');
        }
        
        $consumer_key = $this->consumer_key;
        $consumer_secret = $this->consumer_secret;
        $oauth_access_token = $this->oauth_access_token;
        $oauth_access_token_secret = $this->oauth_access_token_secret;
        
        $oauth = array( 
            'oauth_consumer_key' => $consumer_key,
            'oauth_nonce' => time(),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_token' => $oauth_access_token,
            'oauth_timestamp' => time(),
            'oauth_version' => '1.0'
        );
        
        $getfield = $this->getGetfield();
        
        if (!is_null($getfield))
        {
            $getfields = str_replace('?', '', explode('&', $getfield));
            foreach ($getfields as $g)
            {
                $split = explode('=', $g);
                $oauth[$split[0]] = $split[1];
            }
        }
        
        $base_info = $this->buildBaseString($url, $requestMethod, $oauth);
        $composite_key = rawurlencode($consumer_secret) . '&' . rawurlencode($oauth_access_token_secret);
        $oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));
        $oauth['oauth_signature'] = $oauth_signature;
        
        $this->url = $url;
        $this->oauth = $oauth;
        
        return $this;
    }
    
    /**
     * Perform the actual data retrieval from the API
     * 
     * @param boolean $return If true, returns data.
     * 
     * @return string json If $return param is true, returns json data.
     */
    public function performRequest($return = true)
    {
        if (!is_bool($return)) 
        { 
            throw new Exception('performRequest parameter must be true or false'); 
        }
        
        $json = null;
        $hasCached = false;

        $cacheUID = $this->generateCacheUID();
        
        
        if($this->cache_enabled){
        	if ($this->hasCachedRequest($cacheUID)) {
        		$json = file_get_contents($this->cache_dir . $cacheUID);
        		$hasCached = true;
        	}
        }
        
        if($hasCached && $json){
        	return $json;
        }else{
        
	        $header = array($this->buildAuthorizationHeader($this->oauth), 'Expect:');
	        
	        $getfield = $this->getGetfield();
	        $postfields = $this->getPostfields();
	
	        $options = array( 
	            CURLOPT_HTTPHEADER => $header,
	            CURLOPT_HEADER => false,
	            CURLOPT_URL => $this->url,
	            CURLOPT_RETURNTRANSFER => true
	        );
	
	        if (!is_null($postfields))
	        {
	            $options[CURLOPT_POSTFIELDS] = $postfields;
	        }
	        else
	        {
	            if ($getfield !== '')
	            {
	                $options[CURLOPT_URL] .= $getfield;
	            }
	        }
	
	        $feed = curl_init();
	        curl_setopt_array($feed, $options);
	        $json = curl_exec($feed);
	        curl_close($feed);
        
	        if ($this->cache_enabled) {
	        	$this->writeCache($json, $cacheUID);
	        }
        }

        if ($return) { return $json; }
    }
    
    /**
     * Private method to generate the base string used by cURL
     * 
     * @param string $baseURI
     * @param string $method
     * @param array $params
     * 
     * @return string Built base string
     */
    private function buildBaseString($baseURI, $method, $params) 
    {
        $return = array();
        ksort($params);
        
        foreach($params as $key=>$value)
        {
            $return[] = "$key=" . $value;
        }
        
        return $method . "&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $return)); 
    }
    
    /**
     * Private method to generate authorization header used by cURL
     * 
     * @param array $oauth Array of oauth data generated by buildOauth()
     * 
     * @return string $return Header used by cURL for request
     */    
    private function buildAuthorizationHeader($oauth) 
    {
        $return = 'Authorization: OAuth ';
        $values = array();
        
        foreach($oauth as $key => $value)
        {
            $values[] = "$key=\"" . rawurlencode($value) . "\"";
        }
        
        $return .= implode(', ', $values);
        return $return;
    }
    
    /**
     * Private Method to generate a filename based on request data
     * @return string file name
     */
    private function generateCacheUID(){

    	$getfield = $this->getGetfield();
    	$postfields = $this->getPostfields();
    	
    	$request = serialize($getfield) . serialize($postfields);
    	
    	return md5($request).'.json';
    }
    
    /**
     * Private Method to verify if has cache to current request
     * @param string $cacheUID
     * @return boolean
     */
    private function hasCachedRequest($cacheUID){
    	if (is_file($this->cache_dir . $cacheUID) && file_exists($this->cache_dir . $cacheUID) &&  filemtime($this->cache_dir . $cacheUID) > (time() - ($this->cache_time * 60))){
    		return true;
    	}
    	return false;
    }
    
    /**
     * Private method to write json data in cache file
     * @param string $content json data
     * @param string $cacheUID file name UID (previous generated)
     * @throws Exception
     */
    private function writeCache($content, $cacheUID){
    	if (!is_dir($this->cache_dir)) {
    		@mkdir($this->cache_dir, 0777, true);
    	}
	    	
    	if (is_writable($this->cache_dir)) {
	    	
	    	$filename = $this->cache_dir . $cacheUID;
	    	
	    	$f = fopen($filename, "w+");
	
	    	fwrite($f, $content);
	    	
	    	fclose($f);
	    	
	    	$this->clearCache(false);
    	}else{
    		throw new Exception("Twitter Cache Dir '$this->cache_dir' not Writeable!");
    	}
    }
    
    /**
     * Private Method to clear cache dir
     * @param bool $allCache if true remove all cache, if false remove only expired cache
     */
    private function clearCache($allCache = true){
    	if (is_dir($this->cache_dir)) {
    		if ($dh = opendir($this->cache_dir)) {
    			while (($file = readdir($dh)) !== false) {
    				if (filemtime($this->cache_dir . $file) < (time() - ($this->cache_time * 61)) || $allCache) {
    					@unlink($this->cache_dir . $file);
    				}
    			}
    			closedir($dh);
    		}
    	}
    }
}
