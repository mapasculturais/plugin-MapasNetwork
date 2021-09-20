# plugin-MapasNetwork #
Plugin para integração de sites Mapas Culturais.

## Instalação
Os arquivos e diretórios contidos neste repositório devem ficar disponíveis
para o Mapas Culturais em um diretório, como é feito com os demais plugins.
Seguindo as convenções do Mapas Culturais, o caminho do plugin seria
`/var/www/html/protected/application/plugins/MapasNetwork`. Preenchido esse
requisito, o arquivo de configuração dos plugins deve conter uma entrada que
faz referência ao MapasNetwork, como no exemplo a seguir.
```
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
