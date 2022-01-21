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
            /**
             * Slug do nó local para fins de identificação.
             * Formato: <string>
            */
            "nodeSlug" => "esmapas"
        ],
    ],
];
```
Normalmente esse arquivo de configuração fica em
`/var/www/html/protected/application/conf/conf-common.d/plugins.php`.

## Variáveis de ambiente

A configuração da variável de ambiente `BASE_URL` ̣é obrigatória, pois os jobs
dependem dessa informação em um cenário onde a variável global `$_SERVER` não
contém os dados normalmente disponíveis para a aplicação.

As seguintes variáveis são recomendadas para flexibilizar a configuração
exemplificada na seção anterior.
- `MAPAS_NETWORK_SLUG`
- `MAPAS_NETWORK_NODES`
- `MAPAS_NETWORK_STATE`
