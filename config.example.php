<?php
/*
 * Copie este arquivo para config.php (mesma pasta) e preencha.
 * config.php NAO vai para o git (esta no .gitignore) e fica bloqueado pelo .htaccess.
 */
return [
    'smtp_host'   => 'smtp.titan.email',          // confirme onde fica a caixa de e-mail do dominio (ver MX)
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',                       // 'ssl' (465), 'tls' (587) ou '' (sem criptografia, so teste local)
    'smtp_user'   => 'contato@DOMINIO.com.br',
    'smtp_pass'   => 'COLOQUE_A_SENHA_AQUI',

    'remetente'   => 'contato@DOMINIO.com.br',    // precisa ser a conta autenticada acima
    'nome_site'   => 'Instituto Maxilofacial',
    'destino'     => ['widencreative@gmail.com'], // TESTE. Depois de validar, trocar para o e-mail do cliente.
];
