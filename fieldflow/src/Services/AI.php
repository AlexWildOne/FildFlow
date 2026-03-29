<?php
namespace RoutesPro\Services;

use RoutesPro\Admin\Settings;

interface AIProviderInterface {
    public function complete($prompt, $params=[]);
}

class GoogleAIProvider implements AIProviderInterface {
    protected $key;
    public function __construct(){ $this->key = Settings::get('google_ai_key'); }
    public function complete($prompt, $params=[]){
        $model = $params['model'] ?? 'gemini-1.5-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent?key='.$this->key;
        $payload = ['contents'=>[['parts'=>[['text'=>$prompt]]]]];
        $res = wp_remote_post($url, [
            'headers'=>['Content-Type'=>'application/json'], 'body'=>wp_json_encode($payload), 'timeout'=>30
        ]);
        if (is_wp_error($res)) return null;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }
}

class AzureOpenAIProvider implements AIProviderInterface {
    protected $key; protected $endpoint; protected $deployment;
    public function __construct(){
        $this->key = Settings::get('azure_openai_key');
        $this->endpoint = rtrim(Settings::get('azure_openai_endpoint',''), '/');
        $this->deployment = Settings::get('azure_openai_deployment','');
    }
    public function complete($prompt, $params=[]){
        $api = $this->endpoint.'/openai/deployments/'.$this->deployment.'/chat/completions?api-version=2024-10-01-preview';
        $payload = [
            'messages'=>[['role'=>'user','content'=>$prompt]],
            'temperature'=> $params['temperature'] ?? 0.2,
            'max_tokens'=> $params['max_tokens'] ?? 400
        ];
        $res = wp_remote_post($api, [
            'headers'=>[ 'Content-Type'=>'application/json', 'api-key'=>$this->key ],
            'body'=> wp_json_encode($payload), 'timeout'=>30
        ]);
        if (is_wp_error($res)) return null;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return $body['choices'][0]['message']['content'] ?? null;
    }
}

class OpenAIProvider implements AIProviderInterface {
    protected $key; protected $base; protected $model;
    public function __construct(){
        $this->key   = Settings::get('openai_api_key','');
        $this->base  = rtrim(Settings::get('openai_base_url','https://api.openai.com'), '/');
        $this->model = Settings::get('openai_model','gpt-4o-mini');
    }
    public function complete($prompt, $params=[]){
        $url = $this->base . '/v1/chat/completions';
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role'=>'system','content'=>'Responde em português de Portugal, prático e objetivo.'],
                ['role'=>'user','content'=>$prompt],
            ],
            'temperature' => $params['temperature'] ?? 0.2,
            'max_tokens'  => $params['max_tokens'] ?? 400
        ];
        $res = wp_remote_post($url, [
            'headers'=>[ 'Content-Type'=>'application/json', 'Authorization'=>'Bearer '.$this->key ],
            'body'=> wp_json_encode($payload),
            'timeout'=>30
        ]);
        if (is_wp_error($res)) return null;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return $body['choices'][0]['message']['content'] ?? null;
    }
}

class CopilotWebhookProvider implements AIProviderInterface {
    protected $url; protected $authHeader;
    public function __construct(){
        $this->url = Settings::get('copilot_webhook_url','');
        $this->authHeader = Settings::get('copilot_auth_header','');
    }
    public function complete($prompt, $params=[]){
        if (!$this->url) return null;
        $headers = ['Content-Type'=>'application/json'];
        if ($this->authHeader){
            $parts = explode(':', $this->authHeader, 2);
            if(count($parts)===2){ $headers[trim($parts[0])] = trim($parts[1]); }
        }
        $payload = [
            'prompt'=>$prompt,
            'task'=> $params['task'] ?? 'route_notes',
            'context'=> $params['context'] ?? null
        ];
        $res = wp_remote_post($this->url, [
            'headers'=>$headers,
            'body'=> wp_json_encode($payload),
            'timeout'=>30
        ]);
        if (is_wp_error($res)) return null;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return $body['text'] ?? ($body['message'] ?? null);
    }
}

class AIFactory {
    public static function make(): ?AIProviderInterface {
        $prov = Settings::get('ai_provider','none');
        if ($prov === 'google')  return new GoogleAIProvider();
        if ($prov === 'azure')   return new AzureOpenAIProvider();
        if ($prov === 'openai')  return new OpenAIProvider();
        if ($prov === 'copilot') return new CopilotWebhookProvider();
        return null;
    }
}
