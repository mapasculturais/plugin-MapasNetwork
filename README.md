# plugin-MapasNetwork #
Plugin para integração de sites Mapas Culturais.

## Instalação
Os arquivos e diretórios contidos neste repositório devem ficar disponíveis
para o Mapas Culturais em um diretório, como é feito com os demais plugins.
Seguindo as convenções do Mapas Culturais, o caminho do plugin seria
`/var/www/html/protected/application/plugins/MapasNetwork`. Preenchido esse
requisito, o arquivo de configuração dos plugins deve conter uma entrada que
faz referência ao MapasNetwork, como no exemplo a seguir.
```PHP
<?php
return [
    // entradas para os outros plugins
    // .
    // .
    // .
    "MapasNetwork" => [
        "namespace" => "MapasNetwork",
        "config" => [
            /**
             * Lista das instalações que serão verificadas automaticamente para
             * sugestão de vinculação de contas.
             * Formato: <url1>,<url2>,...
             */
            "nodes" => explode(",", env("MAPAS_NETWORK_NODES", "")),
            /**
             * Filtros para determinar se uma entidade está no escopo desta
             * instalação. Devem ser definidos separadamente para agentes e
             * espaços.
             * Formato: [<campo> => <valor ou lista de valores>, ...]
             */
            "filters" => [
                "agent" => ["En_Estado" => "ES"],
                "space" => ["En_Estado" => "ES"],
            ],
        ],
    ],
];
```
Normalmente esse arquivo de configuração fica em
`/var/www/html/protected/application/conf/conf-common.d/plugins.php`.
##


## Documentação do Usuário Final 
### MapasNetwork, o Plugin Integrador 
<p>Essa é uma documentação preliminar voltada para o usuário final da plataforma Mapas Culturais, isto é, o usuário que utiliza alguma instalação do Mapas.</p>
<p>O Integrador é um plugin que tem por finalidade ser uma ferramenta de integração das informações entre as plataformas Mapas Culturais. </p>
<p>Mais especificamente, ele tem a função de propagar as informações de um mesmo agente, de um Mapa Cultural para outro Mapa Cultural. </p>
<p>Por exemplo: Um usuário que possui um cadastro em uma instalação estadual e um cadastro na instalação nacional pode vincular seus dois cadastros, de modo que ambos os agentes e espaços, da instalação estadual e nacional, passarão a conter as mesmas informações.</p>
<p>É importante ressaltar que para que essa propagação das informações seja realizada, de um Mapa Cultural para o outro, ambas plataformas devem conter o plugin instalado.</p>
<p>A propagação das informações de uma instalação para a outra é realizada através de critérios estabelecidos durante a configuração do plugin. Ou seja, no plugin são configurados filtros, os quais consistem em dados que determinarão se o plugin reconhecerá os dois cadastros realizados em plataformas diferentes.</p>
<p>Esses filtros podem ser os dados dos cadastros, porque os filtros são todas as informações passadas pela API. Isso significa que no cadastro do agente, essas informações podem ser os campos raça/cor, área de atuação, gênero, e-mail público e/ou privado, e todos os demais campos de preenchimento, incluso o CPF e endereço.</p>
<p>Vale ressaltar que é necessário que o usuário possua cadastro em ambas as instalações para que a sincronização seja possível.</p>

### Como funciona? 

<p>Como afirmado, o plugin integrador pode ser dinamicamente configurado. Por padrão, a configuração utilizada é que a sincronização entre os ambientes funcione quando os Agentes possuírem CPF iguais e, também, nomes semelhantes.</p>  
<p>A configuração padrão é feita de forma que as integrações sejam realizadas por seu aspecto georreferenciado, a saber, por instalações: nacionais, estaduais e municipais.</p>
<p>Assim, imagine um caso onde o usuário possui cadastros em uma instalação nacional, em uma instalação estadual e em uma instalação municipal. Nesse exemplo, a instalação municipal se refere a um município dentro do território do estado ao qual a instalação estadual pertence.  </p>
<p>Para auxiliarmos em seu processo imaginativo, a fim de que você entenda, tomemos como exemplo a instalação do Mapa Cultural Brasilero, o Mapa Cultural do Espírito Santo e o Mapa Cultural de Vitória. </p>
<p>O primeiro critério que se deve levar em consideração é qual será o dado do agente ou espaço que o plugin utilizará para reconhecer os cadastros em ambas as instalações. Ou seja, se o plugin for configurado para utilizar o CPF como critério de reconhecimento em instalações diferentes. </p>
<p>Quando um usuário possuir cadastro em mais de uma instalação que tenha o plugin instalado e configurado, com o mesmo CPF, a possibilidade da vinculação entre as contas será indicada.</p>
<p>Ao realizar o vínculo entre os cadastros, o segundo critério é levado em conta, isto é, quais dados serão propagados e para quais instalações. </p>
<p>Os dados são propagados com base nos dados do agente e do espaço que foram atualizados mais recentemente. </p>
<p>Quando um agente e um espaço não possuem endereço, mas foram criados na instalação do Espírito Santo, eles não irão propagar para a instalação de Vitória e irão propagar para a instalação nacional.</p>
<p>Quando um agente e um espaço foram criados na instalação do Espírito Santo, com o endereço em Vitória, eles propagam para a instalação nacional e de Vitória.</p>
<p>Todos os agentes e espaços se propagam para a instalação Nacional. Caso um agente ou espaço seja criado na instalação nacional sem endereço,  a ausência da informação de endereço não deve propagar. Caso possua endereço, esses dados se propagam para as outras instalações.</p>
<p>Vale notar que a área de atuação sempre propaga pela atualização de informações mais recente, mesmo que o outro agente possua mais áreas de atuação preenchidas.</p>
<p>Por fim, caso o plugin não reconheça o cadastro em outra instalação, é possível realizar o vínculo pelo botão “fazer vinculação com outro mapa cultural”.</p>
