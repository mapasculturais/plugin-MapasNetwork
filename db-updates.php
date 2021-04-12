<?php

use function MapasCulturais\__column_exists;
use function MapasCulturais\__exec;
use function MapasCulturais\__sequence_exists;
use function MapasCulturais\__table_exists;

return [
    "create table network_node" => function () {
        if (!__sequence_exists("network_node_id_seq")) {
            __exec("CREATE SEQUENCE network_node_id_seq
                INCREMENT BY 1 MINVALUE 1 START 1");
        }
        if (!__table_exists("network_node")) {
            __exec("CREATE TABLE network_node (
                id INT NOT NULL,
                user_id INT DEFAULT NULL,
                url VARCHAR(255) NOT NULL,
                create_timestamp TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status INT NOT NULL,
                PRIMARY KEY(id))");
            __exec("CREATE INDEX IDX_FE222B32A76ED395
                ON network_node (user_id)");
            __exec("ALTER TABLE network_node ADD
                CONSTRAINT FK_FE222B32A76ED395
                FOREIGN KEY (user_id) REFERENCES usr (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE");
        }
        return true;
    },
    "add user_app_pubk to network_node" => function () {
        if (!__column_exists("network_node", "user_app_pubk")) {
            __exec("ALTER TABLE network_node ADD
                user_app_pubk VARCHAR(255) NOT NULL");
            __exec("ALTER TABLE network_node ADD
                name VARCHAR(255) DEFAULT '' NOT NULL");
            __exec("ALTER TABLE network_node ADD
                CONSTRAINT FK_FE222B327049A56E
                FOREIGN KEY (user_app_pubk) REFERENCES user_app (public_key)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE");
            __exec("CREATE INDEX IDX_FE222B327049A56E
                ON network_node (user_app_pubk)");
        }
        return true;
    },
];