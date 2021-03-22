<?php
namespace MapasNetwork;

use Doctrine\ORM\Mapping as ORM;
//use MapasCulturais\App;

/**
 * Node
 *
 * @property-read int $id
 * @property \MapasCulturais\Entities\User $user the user whose account this node links to another installation
 * @property string $url the base URL of the node
 * @property-read \DateTime $createTimestamp
 * @property int $status
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
    protected $status;

    /**
     * @var \MapasCulturais\Entities\User
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\User", fetch="LAZY")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="user_id", referencedColumnName="id", onDelete="CASCADE")
     * })
     */
    protected $user;



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