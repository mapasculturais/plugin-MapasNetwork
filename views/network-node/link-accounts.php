<?php

$this->layout = 'nolayout'; ?>

<div class="network-container">

    <header>
        <span class="icon"><i class="fas fa-plug"></i></span>
        <h1>Vinculação de Mapas Culturais</h1>
    </header>

    <div class="card">
        <p>
            Você está prestes a vincular sua conta do <strong>Mapa da Cultura Brasileira</strong> ao <strong>Mapa Cultural do ES</strong>. <br>
            Confirme se você deseja fazer a vinculação
        </p>

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
                        <p>emaildaconta@gmail.com</p>
                        <a href="" target="_blank" rel="noopener noreferrer">Mapa Cultural do ES</a>
                    </div>
                </div>
                <div class="agent">
                    <div class="thumb">
                        <img src="<?php $this->asset('img/avatar--agent.png'); ?>" alt="">
                    </div>
                    <div class="content">
                        <p>emaildaconta@gmail.com</p>
                        <a href="" target="_blank" rel="noopener noreferrer">Mapa da Cultura Brasileira</a>
                    </div>
                </div>
            </div>
        </div>

        <footer>
            <button>Cancelar</button>
            <button>Confirmar</button>
        </footer>
    </div>

</div>