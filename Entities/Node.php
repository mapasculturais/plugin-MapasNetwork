<?php
namespace MapasNetwork\Entities;

use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\App;
use MapasCulturais\i;
use MapasSDK\MapasSDK;
use MapasNetwork\Plugin;

/**
 * Node
 *
 * @property-read int $id
 * @property \MapasCulturais\Entities\User $user the user whose account this node links to another installation
 * @property string $url the base URL of the node
 * @property-read string $entityMetadataKey
 * @property-read \DateTime $createTimestamp
 * @property int $status
 *
 * @property-read \DateTime $createTimestamp
 * 
 * @property-read MapasSDK $api
 * 
 * @ORM\Table(name="network_node")
 * @ORM\Entity
 * @ORM\entity(repositoryClass="MapasCulturais\Repository")
 * @ORM\HasLifecycleCallbacks
 */
class Node extends \MapasCulturais\Entity
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="network_node_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var string
     *
     * @ORM\Column(name="url", type="string", nullable=false)
     */
    protected $url;

    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", nullable=false, options={"default":""})
     */
    protected $name;

    /**
     * @var \MapasCulturais\Entities\User
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\User", fetch="LAZY")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="user_id", referencedColumnName="id", onDelete="CASCADE")
     * })
     */
    protected $user;

    /**
     * @var \MapasCulturais\Entities\UserApp
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\UserApp", fetch="LAZY")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="user_app_pubk", referencedColumnName="public_key", nullable=false, onDelete="CASCADE")
     * })
     */
    protected $userApp;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_timestamp", type="datetime", nullable=false)
     */
    protected $createTimestamp;

    /**
     * @var integer
     *
     * @ORM\Column(name="status", type="integer", nullable=false)
     */
    protected $status = 1;

    function __construct() {
        $app = App::i();
        $this->user = $app->user;

        parent::__construct();
    }

    public function getControllerId()
    {
        return "network-node";
    }

    /**
     * @return string|null
     */
    protected function key() 
    {
        $folder = Plugin::getPrivatePath();
        $key_filename = $folder . sha1("key:{$this->url}:{$this->user->id}");
        
        return file_exists($key_filename) ? file_get_contents($key_filename) : null;        
    }

    function setKeyPair(string $public, string $private) 
    {
        $this->checkPermission('setKeys');
        
        $folder = Plugin::getPrivatePath();
        $key_filename = $folder . sha1("key:{$this->url}:{$this->user->id}");
        $pair_filename = $folder. sha1("pair:{$this->url}:{$this->user->id}");

        $key = openssl_random_pseudo_bytes(64);
        file_put_contents($key_filename, $key);

        $decrypted = json_encode([$private, $public]);
        $encrypted = openssl_encrypt($decrypted,"AES-128-ECB",$key);
        file_put_contents($pair_filename,$encrypted);

    }

    protected function _getKeyPair() 
    {
        $this->checkPermission('viewKeys');
        $folder = Plugin::getPrivatePath();

        $key = $this->key();
        $pair_filename = $folder. sha1("pair:{$this->url}:{$this->user->id}");
        
        if($key && file_exists($pair_filename)) {
            $encrypted = file_get_contents($pair_filename);
            $key_json = openssl_decrypt($encrypted, "AES-128-ECB", $key);
            return json_decode($key_json);   
        }
    }

    protected function _getPrivateKey () 
    {
        $pair = $this->_getKeyPair();
        return $pair[0];
    }

    protected function _getPublicKey () 
    {
        $pair = $this->_getKeyPair();
        return $pair[1];
    }

    protected $_sdk = null;

    /**
     * @return MapasSDK
     */
    function getApi ()
    {
        if(!$this->_sdk) {
            $this->_sdk = new MapasSDK($this->url, $this->_getPublicKey(), $this->_getPrivateKey());
        }

        return $this->_sdk;
    }

    function getEntityMetadataKey() {
        // @todo trocar por slug do nó
        $slug = $this->slug;

        return "network__{$slug}_entity_id";
    }

    function getSlug() {
        // @todo trocar pelo slug do nó
        return str_replace('.', '', parse_url($this->url, PHP_URL_HOST));
    }

    protected function canUserViewKeys($user) 
    {
        return $this->user->id == $user->id;
    }

    protected function canUserSetKeys($user) 
    {
        return $this->user->id == $user->id;
    }

    /**
     * Retorna a configuração de filtros do nó
     * 
     * @param string $entity 
     * @return array
     */
    public function getFilters(string $entity) {
        $entity = strtolower($entity);
        
        $app = App::i();
        $cache_key = $this->url . ':filters';

        if (false && $app->cache->contains($cache_key)) {
            $filters = $app->cache->fetch($cache_key);
        } else {
            $response = $this->api->apiGet('network-node/filters');

            $filters = $response->response ?? [];

            $app->cache->save($cache_key, $filters, 30 * MINUTE_IN_SECONDS);
        }

        return (array) $filters->$entity ?? [];
    }

    //============================================================= //
    // The following lines are used by MapasCulturais hook system.
    // Please do not change them.
    // ============================================================ //

    /** @ORM\PrePersist */
    public function prePersist($args=null) { parent::prePersist($args); }
    /** @ORM\PostPersist */
    public function postPersist($args=null) { parent::postPersist($args); }

    /** @ORM\PreRemove */
    public function preRemove($args=null) { parent::preRemove($args); }
    /** @ORM\PostRemove */
    public function postRemove($args=null) { parent::postRemove($args); }

    /** @ORM\PreUpdate */
    public function preUpdate($args=null) { parent::preUpdate($args); }
    /** @ORM\PostUpdate */
    public function postUpdate($args=null) { parent::postUpdate($args); }
}