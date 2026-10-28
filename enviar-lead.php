<?php
/*
 * Recebe o JSON do formulario de inscricao e envia os dados por e-mail via SMTP.
 * Credenciais ficam em config.php (nao versionado). Modelo: config.example.php.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/phpmailer/Exception.php';
require __DIR__ . '/phpmailer/PHPMailer.php';
require __DIR__ . '/phpmailer/SMTP.php';

date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $corpo): void
{
    http_response_code($status);
    echo json_encode($corpo);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['ok' => false, 'erro' => 'metodo invalido']);
    exit;
}

$arquivo_config = __DIR__ . '/config.php';
if (!is_file($arquivo_config)) {
    error_log('enviar-lead: config.php ausente');
    responder(500, ['ok' => false, 'erro' => 'configuracao ausente']);
    exit;
}
$cfg = require $arquivo_config;

$dados = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($dados)) {
    responder(400, ['ok' => false, 'erro' => 'corpo invalido']);
    exit;
}

// Texto simples, sem quebras de linha e com tamanho limitado
$texto = function ($chave, $max = 200, $multilinha = false) use ($dados): string {
    $v = $dados[$chave] ?? '';
    if (!is_scalar($v)) return '';
    $v = strip_tags((string)$v);
    // Campos de uma linha: colapsa espacos e quebras. Multilinha: preserva as quebras.
    $v = $multilinha ? preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $v)) : preg_replace('/\s+/u', ' ', $v);
    return mb_substr(trim($v), 0, $max);
};

$nome     = $texto('nome');
$telefone = $texto('telefone', 40);
$whatsapp = $texto('whatsapp', 40);
$email    = $texto('email');
$cro      = $texto('cro');
$cidade   = $texto('cidade');
$estado   = $texto('estado');
$mensagem = $texto('mensagem', 1000, true);
$termos   = ($dados['termos'] ?? '') === 'on' || ($dados['termos'] ?? false) === true;

$tel_ok = fn($t) => strlen(preg_replace('/\D/', '', $t)) >= 8;
if (mb_strlen($nome) < 2 || !$tel_ok($telefone) || !$tel_ok($whatsapp)
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($cro) < 2
    || mb_strlen($cidade) < 2 || mb_strlen($estado) < 2 || !$termos) {
    responder(422, ['ok' => false, 'erro' => 'dados invalidos']);
    exit;
}

$digitos_wa = preg_replace('/\D/', '', $whatsapp);
$link_wa = 'https://wa.me/' . (strlen($digitos_wa) <= 11 ? '55' : '') . $digitos_wa;

$campos = [
    'Nome'      => $nome,
    'Telefone'  => $telefone,
    'WhatsApp'  => $whatsapp,
    'E-mail'    => $email,
    'CRO'       => $cro,
    'Cidade'    => $cidade,
    'Estado'    => $estado,
    'Mensagem'  => $mensagem !== '' ? $mensagem : '-',
    'Recebido em' => date('d/m/Y H:i'),
];

$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$html = '<table cellpadding="8" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px">';
$alt  = '';
foreach ($campos as $rotulo => $valor) {
    $html .= '<tr><td style="border:1px solid #ddd;background:#f6f6f6"><b>' . $e($rotulo) . '</b></td>'
           . '<td style="border:1px solid #ddd">' . nl2br($e($valor)) . '</td></tr>';
    $alt  .= "$rotulo: $valor\n";
}
$html .= '</table><p style="font-family:Arial,sans-serif;font-size:14px"><a href="' . $e($link_wa) . '">Chamar no WhatsApp</a></p>';
$alt  .= "WhatsApp (link): $link_wa\n";

// Responde ao visitante antes de falar com o SMTP, para o formulario nao esperar
ignore_user_abort(true);
responder(200, ['ok' => true]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    header('Connection: close');
    header('Content-Length: ' . ob_get_length());
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host        = $cfg['smtp_host'];
    $mail->Port        = (int)$cfg['smtp_port'];
    $mail->SMTPAuth    = !empty($cfg['smtp_user']);
    $mail->Username    = $cfg['smtp_user'] ?? '';
    $mail->Password    = $cfg['smtp_pass'] ?? '';
    $mail->SMTPSecure  = $cfg['smtp_secure'] ?? '';
    $mail->SMTPAutoTLS = ($cfg['smtp_secure'] ?? '') !== '';
    $mail->Timeout     = 15;
    $mail->CharSet     = 'UTF-8';

    $mail->setFrom($cfg['remetente'], $cfg['nome_site']);
    $mail->addReplyTo($email, $nome);
    foreach ((array)$cfg['destino'] as $para) {
        $mail->addAddress($para);
    }
    $mail->isHTML(true);
    $mail->Subject = 'Novo contato pelo site - ' . $cfg['nome_site'];
    $mail->Body    = $html;
    $mail->AltBody = $alt;
    $mail->send();
} catch (Exception $ex) {
    // Nao perde o lead: grava em arquivo protegido pelo .htaccess e registra o erro
    error_log('enviar-lead: falha SMTP: ' . $ex->getMessage());
    @file_put_contents(__DIR__ . '/leads-falhos.log', $alt . "---\n", FILE_APPEND | LOCK_EX);
}
