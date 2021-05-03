<?php

$this->layout = 'nolayout'; ?>

<div class="network-container">

    <header>
        <span class="icon"><i class="fas fa-plug"></i></span>
        <h1>Vinculação de Mapas Culturais</h1>
    </header>

    <div class="card">
        <p>
            Você está prestes a vincular sua conta do <strong><?php $this->dict('site: name'); ?></strong> ao <strong><?php echo $origin_name; ?></strong>. <br>
            Confirme se você deseja fazer a vinculação
        </p>

        <form action="<?=$this->controller->createUrl("confirmLinkAccount")?>" method="get">
        <div class="integration">
            <div class="roadmap">
                <div class="top"></div>
                <div class="line"></div>
                <div class="icon">
                    <i class="fas fa-plug"></i>
                </div>
            </div>
            <div>
                <div class="agent">
                    <div class="thumb">
                        <img src="<?php $this->asset('img/avatar--agent.png'); ?>" alt="">
                    </div>
                    <div class="content">
                        <!-- Email da conta de origem
                        <p></p>
                        -->
                        <a href="" target="_blank" rel="noopener noreferrer"><?php echo $origin_name; ?></a>
                    </div>
                </div>
                <div class="agent">
                    <div class="thumb">
                        <img src="<?php $this->asset('img/avatar--agent.png'); ?>" alt="">
                    </div>
                    <div class="content">
                        <p><?php echo $app->user->email; ?></p>
                        <a href="" target="_blank" rel="noopener noreferrer"><?php $this->dict('site: name'); ?></a>
                    </div>
                </div>
                <div>
                    <label for="profile-source">Usar o perfil:</label>
                    <select name="profileSource" id="profile-source">
                        <option value="source">do <?=$origin_name?></option>
                        <option value="destination">do <?=$this->dict("site: name")?></option>
                    </select>
                </div>
            </div>
        </div>

        <footer>
            <!--
            <a href="<?php echo $this->controller->createUrl('cancelAccountLink'); ?>">
                <button>Cancelar</button>
            </a>
            <a href="<?php echo $this->controller->createUrl('confirmLinkAccount'); ?>">
                <button>Confirmar</button>
            </a>
            -->
            <input type="submit" value="Cancelar" formaction="<?=$this->controller->createUrl("cancelAccountLink")?>" />
            <input type="submit" value="Confirmar" />
        </footer>
        </form>
    </div>

</div>