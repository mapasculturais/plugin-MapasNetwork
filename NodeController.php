<?php

namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\UserApp;
use MapasCulturais\i;
use MapasCulturais\Traits;
use MapasSDK\MapasSDK;

class NodeController extends \MapasCulturais\Controller
{
    use Traits\ControllerAPI;

    function __construct()
    {
        $this->layout = "mapas-network";
        return;
    }

    public function GET_index()
    {
        $this->render("mapas-network");
        return;
    }

    function GET_confirmLinkAccount()
    {
        $app = App::i();

        $confirmed = $_SESSION['mapas-network:confirmed'] = true;

        $app->redirect();
    }

    public function GET_linkAccounts()
    {

        $connect_to = $_SESSION['mapas-network:to'] ?? $this->data['to'] ?? null;
        $name = $_SESSION['mapas-network:name'] ?? base64_decode($this->data['name'] ?? null) ?: $connect_to;
        $isConfirmed = $_SESSION['mapas-network:confirmed'] ?? $this->data['confirmed'] ?? null;

        $_SESSION['mapas-network:confirmed'] = $isConfirmed;
        $_SESSION['mapas-network:to'] = $connect_to;
        $_SESSION['mapas-network:name'] = $name;

        eval(\psy\sh());

        $this->render("link-accounts");
        return;
    }

    public function GET_panel()
    {
        $this->requireAuthentication();
        $app = App::i();
        $nodeRepo = $app->repo("\\MapasNetwork\\Node");
        $this->render("panel-mapas-network-main", [
            "nodes" => $nodeRepo->findBy(["user" => $app->user]),
        ]);
        return;
    }

    function createToken() {
        $app = App::i();

        $token = App::getToken(32);
        $secret = App::getToken(64);

        $verifier = (object) [
            'secret' => $secret,
            'userId' => $app->user->id
        ];

        // o tempo limite para o processo de vinculação ser concluído é de 5 minutos
        $app->cache->save("{$token}:secret", $secret, 300);
        $app->cache->save("{$token}:verifier", $verifier, 300);

        return $token;
    }


    function checkTokenSecret($token, $secret, $verify_user = false) {
        $app = App::i();

        return ($verifier = $app->cache->fetch("{$token}:verifier")) && 
                $verifier->secret === $secret && 
                (!$verify_user || $verifier->userId === $app->user->id);
    }

    function POST_create()
    {
        $this->requireAuthentication();
        $app = App::i();
        $url = $this->postData['url'];

        $create_token = $this->createToken();

        $app->cache->save("{$create_token}:url". $url, 300);

        $site_name = urlencode(base64_encode($app->siteName));
        $app->redirect("{$url}{$this->id}/connect?to={$app->baseUrl}&token={$create_token}&name={$site_name}");
    }

    /**
     * Verifica o token e, caso válido, retorna o segredo.
     * 
     * Um token só é válido uma única vez e caso haja uma segunda tentativa de verificar,
     * o verificador que contém o segredo é apagado.
     */
    function GET_verifyConnectionToken() 
    {
        $app = App::i();
        $token = $this->data['token'] ?? null;
        $return_token = $this->data['returnToken'] ?? null;
        if ($token){
            $secret = $app->cache->fetch("{$token}:secret");
            if ($secret) {
                $app->cache->delete("{$token}:secret");
                if ($return_token) {
                    $app->cache->save("{$token}:returnToken", $return_token, 300);
                }
                $app->log->debug("token válido: {$token}");
                $app->halt(200, $secret);
            } else {
                $app->log->debug("secret não encontrado: {$token}");
                $app->cache->delete("{$token}:verifier");
            }
        } else {
            $app->log->debug("token inválido: {$token}");
        }
    }

    function GET_connect() 
    {
        $app = App::i();

        $connect_to = $_SESSION['mapas-network:to'] ?? $this->data['to'] ?? null;
        $create_token = $_SESSION['mapas-network:token'] ?? $this->data['token'] ?? null;
        $name = $_SESSION['mapas-network:name'] ?? base64_decode($this->data['name'] ?? null) ?: $connect_to;
        $isConfirmed = $_SESSION['mapas-network:confirmed'] ?? $this->data['confirmed'] ?? null;

        $_SESSION['mapas-network:to'] = $connect_to;
        $_SESSION['mapas-network:token'] = $create_token;
        $_SESSION['mapas-network:name'] = $name;
        $_SESSION['mapas-network:confirmed'] = $isConfirmed;

        $this->requireAuthentication();

        eval(\psy\sh());
        // Verificar se já foi confirmado.
        // Redirecionar caso ainda não esteja confirmado
        if (!$isConfirmed) {
            //eval(\psy\sh());
            $app->redirect("{$connect_to}{$this->id}/linkAccounts");
        }

        if ($connect_to && $create_token) {
            $connect_token = $this->createToken();
            
            // verifica o token e recebe o segredo que será enviado no retorno
            $create_secret = file_get_contents("{$connect_to}{$this->id}/verifyConnectionToken?token={$create_token}&returnToken={$connect_token}");
            
            if ($create_secret) {
                // cria o App
                $user_app = new UserApp;
                $user_app->name = i::__('Rede Mapas') . ": {$name} ({$connect_to})";
                $user_app->save(true);
                
                $app->log->debug("user app criado: {$user_app->name}");

                $app->cache->save("{$connect_token}:userAppId", $user_app->id, 300);

                $site_name = urlencode(base64_encode($app->siteName));

                $app->redirect("{$connect_to}{$this->id}/return?from={$app->baseUrl}&token={$create_token}&s={$create_secret}&returnToken={$connect_token}&name={$site_name}");                
            }
        }
    }

    function GET_return ()
    {
        $app = App::i();

        $connect_from = $_SESSION['mapas-network:from'] ?? $this->data['from'] ?? null;
        $create_token = $_SESSION['mapas-network:token'] ?? $this->data['token'] ?? null;
        $create_secret = $_SESSION['mapas-network:secret'] ?? $this->data['s'] ?? null;
        $connect_token = $_SESSION['mapas-network:returnToken'] ?? $this->data['returnToken'] ?? null;
        $name = $_SESSION['mapas-network:name'] ?? base64_decode($this->data['name'] ?? null) ?: $connect_from;

        $_SESSION['mapas-network:from'] = $connect_from;
        $_SESSION['mapas-network:token'] = $create_token;
        $_SESSION['mapas-network:secret'] = $create_secret;
        $_SESSION['mapas-network:returnToken'] = $connect_token;
        $_SESSION['mapas-network:name'] = $name;

        $this->requireAuthentication();

        unset(
            $_SESSION['mapas-network:from'],
            $_SESSION['mapas-network:token'],
            $_SESSION['mapas-network:secret'],
            $_SESSION['mapas-network:returnToken'],
            $_SESSION['mapas-network:name']
        );

        if ($connect_token && $this->checkTokenSecret($create_token, $create_secret, true)) {
            
            $connect_secret = file_get_contents("{$connect_from}{$this->id}/verifyConnectionToken?token={$connect_token}");
            if ($connect_secret) {
                $keys = json_decode(file_get_contents("{$connect_from}{$this->id}/getKeys?token={$connect_token}&s=$connect_secret"));

                $node = new Node;
                $node->url = $connect_from;
                $node->status = 1;
                $node->save(true);

                $node->setKeyPair($keys[0], $keys[1]);

                // cria o App
                $user_app = new UserApp;
                $user_app->name = i::__('Rede Mapas') . ": {$name} ({$connect_from})";
                $user_app->save(true);

                $node->api->apiPost("{$this->id}/finish", [
                    'publicKey' => $user_app->getPublicKey(), 
                    'privateKey' => $user_app->getPrivateKey(),
                    'connecto_to' => $app->baseUrl,
                    'name' => $app->siteName
                ]);

                $app->redirect($this->createUrl('panel'));
            }
        }
    }

    function GET_getKeys()
    {
        $connect_token = $this->data['token'] ?? null;
        $connect_secret = $this->data['s'] ?? null;

        if ($this->checkTokenSecret($connect_token, $connect_secret)) {
            $app = App::i();
            $user_app_id = $app->cache->fetch("$connect_token:userAppId");
            $user_app = $app->repo('UserApp')->find($user_app_id);

            $this->json([$user_app->publicKey, $user_app->privateKey]);
        }
    }

    function POST_finish()
    {
        $this->requireAuthentication();

        $connect_from = $this->postData['connecto_to'];
        $site_name = $this->postData['name'];
        $public_key = $this->postData['publicKey'];
        $private_key = $this->postData['privateKey'];

        $node = new Node;
        $node->url = $connect_from;
        $node->status = 1;
        $node->save(true);

        $node->setKeyPair($public_key, $private_key);
    }

    function GET_cancelAccountLink()
    {
        $app = App::i();

        $url = $_SESSION['mapas-network:to'] . '/network-node/panel/';

        unset(
            $_SESSION['mapas-network:to'],
            $_SESSION['mapas-network:token'],
            $_SESSION['mapas-network:name']
        );

        //eval(\psy\sh());

        $app->redirect($url);
    }
}
