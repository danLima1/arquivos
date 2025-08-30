<?php
session_start();
date_default_timezone_set("America/Sao_Paulo");
require_once('vendor/autoload.php');
require_once('./config/db.php');
require_once('./functions/getUserById.php');
require_once('./functions/cpfList.php'); // Inclui o arquivo com a lista de CPFs

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Obtém o ID da URL de forma segura
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

if ($id) {
    // Chama a função para obter o usuário
    $cliente = getUserById($conn, $id);

    // Se encontrou o usuário, armazena os dados na sessão
    if ($cliente) {
        $_SESSION['user_id'] = $cliente['id']; // Salva o ID do usuário na sessão
    } else {
        header("Location: https://www.olx.com.br");
        exit();
    }
} else {
    // Se o ID não foi passado, redireciona para olx.com.br
    header("Location: https://www.olx.com.br");
    exit();
}

// Fecha a conexão com o banco de dados
if (!isset($_POST["valorFinal"]) || empty($_POST["valorFinal"])) {
    echo json_encode(["error" => "Erro: Nenhum valor foi recebido."]);
    exit();
}

$valorCentavos = intval($_POST["valorFinal"]);

// Seleciona um CPF aleatório da lista
$cpfAleatorio = $cpfs[array_rand($cpfs)];

// Configurações
$secretKey = "sk_0Ffy1JR6nj2WsZuOZvmCtiWO4eQ2WM5GlzWuXE4lyaYD";
$apiUrl = "https://api.blackcatpagamentos.com/v1/transactions";

// Gera dados do cliente
$nomes_masculinos = [
    'João', 'Pedro', 'Lucas', 'Miguel', 'Arthur', 'Gabriel', 'Bernardo', 'Rafael',
    'Gustavo', 'Felipe', 'Daniel', 'Matheus', 'Bruno', 'Thiago', 'Carlos'
];

$nomes_femininos = [
    'Maria', 'Ana', 'Julia', 'Sofia', 'Isabella', 'Helena', 'Valentina', 'Laura',
    'Alice', 'Manuela', 'Beatriz', 'Clara', 'Luiza', 'Mariana', 'Sophia'
];

$sobrenomes = [
    'Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 
    'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 
    'Almeida', 'Lopes', 'Soares', 'Fernandes', 'Vieira', 'Barbosa'
];

// Gera dados do cliente
$genero = rand(0, 1);
$nome = $genero ? 
    $nomes_masculinos[array_rand($nomes_masculinos)] : 
    $nomes_femininos[array_rand($nomes_femininos)];

$sobrenome1 = $sobrenomes[array_rand($sobrenomes)];
$sobrenome2 = $sobrenomes[array_rand($sobrenomes)];

$nome_cliente = "$nome $sobrenome1 $sobrenome2";
$placa = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90)) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);

// Gerar email baseado no nome
function gerarEmail($nome) {
    $nome = strtolower(trim($nome));
    $nome = preg_replace('/[^a-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $nome));
    $dominios = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com.br', 'uol.com.br'];
    $dominio = $dominios[array_rand($dominios)];
    
    return $nome . rand(1, 999) . '@' . $dominio;
}

$email = gerarEmail($nome_cliente);

// Preparar dados para a API
$pixData = [
    'amount' => $valorCentavos, // Valor em unidades inteiras
    'paymentMethod' => 'pix', // Definindo o método de pagamento como PIX
    'pix' => [
        'expiresInDays' => 1 // Expira em 1 dia
    ],
    'customer' => [
        'name' => $nome_cliente,
        'email' => $email,
        'phone' => '(11) 99999-9999', // Telefone é obrigatório
        'document' => [
            'type' => 'cpf',
            'number' => preg_replace('/[^0-9]/', '', $cpfAleatorio)
        ],
        'externalRef' => 'IPVA-' . $placa . '-' . time() // Referência externa
    ],
    'items' => [
        [
            'title' => 'Liberação de Benefício - Placa: ' . $placa,
            'unitPrice' => $valorCentavos, // Valor em unidades inteiras
            'quantity' => 1,
            'tangible' => false,
            'externalRef' => 'IPVA-' . $placa
        ]
    ],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
];

try {
    // Fazer requisição para a API
    $authorization = 'Basic ' . base64_encode($secretKey . ':x');
    
    $client = new Client();
    $response = $client->request('POST', $apiUrl, [
        'json' => $pixData,
        'headers' => [
            'Authorization' => $authorization,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ],
    ]);

    $responseData = json_decode($response->getBody(), true);
    
    if (!isset($responseData['id'])) {
        throw new Exception("ID não encontrado na resposta da API");
    }
    
    // Extrair os dados do PIX da resposta
    $pixCopiaECola = '';
    if (isset($responseData['pix']['qrcode'])) {
        $pixCopiaECola = $responseData['pix']['qrcode'];
    } elseif (isset($responseData['pix']['qrCode'])) {
        $pixCopiaECola = $responseData['pix']['qrCode'];
    } elseif (isset($responseData['pix']['code'])) {
        $pixCopiaECola = $responseData['pix']['code'];
    } elseif (isset($responseData['pix']['text'])) {
        $pixCopiaECola = $responseData['pix']['text'];
    } elseif (isset($responseData['qrcode'])) {
        $pixCopiaECola = $responseData['qrcode'];
    }
    
    // Se não conseguir obter os dados da API, usar valor default
   
     
     $qrcodeImagem = "https://api.qrserver.com/v1/create-qr-code/?data=" . urlencode($pixCopiaECola) . "&size=300x300";

    // Insere a transação no banco de dados após sucesso
    $transactionId = $responseData['id'];
    $criadoEm = date("Y-m-d H:i:s");
    $atualizadoEm = $criadoEm;

    $sql = "INSERT INTO transacoes (transactionId, valor, criado_em, atualizado_em) 
            VALUES ('$transactionId', '$valorCentavos', '$criadoEm', '$atualizadoEm')";
    
    if (mysqli_query($conn, $sql)) {
       //
    } else {
        echo json_encode(["error" => "Erro ao registrar transação no banco de dados"]);
    }

} catch (RequestException $e) {
    echo "Erro: " . $e->getMessage() . "\n";  
    if ($e->getResponse()) {
        echo "Resposta: " . $e->getResponse()->getBody();
    }
    $pixCopiaECola = '';
    $qrcodeImagem = '';
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    $pixCopiaECola = '';
    $qrcodeImagem = '';
}
?>

<html style=""><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      
      <title>Compra Segura | OLX </title>
      <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1">
      <meta name="next-head-count" content="3">
      <meta name="theme-color" content="#6E0AD6">
      <link rel="icon" href="https://olx.comprasegurasx.com.br/favicon.ico" sizes="any">
      <link rel="apple-touch-icon" href="https://olx.comprasegurasx.com.br/apple-touch-icon.png">
      <noscript data-n-css=""></noscript>
       <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
      <style>body{font-size:100%;}</style>
      <style>
         
         *,*::before,*::after{-webkit-box-sizing:border-box;-moz-box-sizing:border-box;box-sizing:border-box;font-family:'Nunito Sans','Helvetica Neue',Helvetica,Arial,sans-serif;font-display:swap;-webkit-font-smoothing:antialiased;}
         body,h3,p{margin:0;}
         body{min-height:100vh;text-rendering:optimizeSpeed;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;line-height:1.5;}
         img{max-width:100%;display:block;}
         button{font:inherit;}
         button,[type='button']{-webkit-appearance:button;}
         @media (prefers-reduced-motion:reduce){
         *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;scroll-behavior:auto!important;}
         }
         
        
         :root{--border-radius-none:0;--border-radius-xxs:2px;--border-radius-xs:4px;--border-radius-sm:8px;--border-radius-md:16px;--border-radius-lg:24px;--border-radius-pill:500px;--border-radius-circle:50%;--border-width-none:0;--border-width-hairline:1px;--border-width-thin:2px;--border-width-thick:4px;--border-width-heavy:8px;--media-query-sm:360px;--media-query-md:840px;--media-query-lg:1200px;--media-query-xl:1500px;--media-query-xxl:1720px;--color-primary-70:#fff3e6;--color-primary-80:#ffe1bf;--color-primary-90:#ffbb73;--color-primary-100:#f28000;--color-primary-110:#df7400;--color-primary-120:#cb6700;--color-primary-130:#b35a00;--color-primary-darkest:#b35a00;--color-primary-darker:#cb6700;--color-primary-dark:#df7400;--color-primary-medium:#f28000;--color-primary-light:#ffbb73;--color-primary-lighter:#ffe1bf;--color-primary-lightest:#fff3e6;--color-secondary-70:#f0e6ff;--color-secondary-80:#c599ff;--color-secondary-90:#994dfa;--color-secondary-100:#6e0ad6;--color-secondary-110:#5c08b2;--color-secondary-120:#49078f;--color-secondary-130:#37056b;--color-secondary-darkest:#37056b;--color-secondary-darker:#49078f;--color-secondary-dark:#5c08b2;--color-secondary-medium:#6e0ad6;--color-secondary-light:#994dfa;--color-secondary-lighter:#c599ff;--color-secondary-lightest:#f0e6ff;--color-neutral-70:#ffffff;--color-neutral-80:#f5f6f7;--color-neutral-90:#cfd4dd;--color-neutral-100:#8994a9;--color-neutral-110:#5e6a82;--color-neutral-120:#3c4453;--color-neutral-130:#1a1d23;--color-neutral-darkest:#1a1d23;--color-neutral-darker:#3c4453;--color-neutral-dark:#5e6a82;--color-neutral-medium:#8994a9;--color-neutral-light:#cfd4dd;--color-neutral-lighter:#f5f6f7;--color-neutral-lightest:#ffffff;--color-feedback-success-80:#def9cc;--color-feedback-success-90:#8ce563;--color-feedback-success-100:#24a148;--color-feedback-success-110:#197b35;--color-feedback-success-120:#105323;--color-feedback-success-darkest:#105323;--color-feedback-success-dark:#197b35;--color-feedback-success-medium:#24a148;--color-feedback-success-light:#8ce563;--color-feedback-success-lightest:#def9cc;--color-feedback-error-80:#fff5f5;--color-feedback-error-90:#f48787;--color-feedback-error-100:#e22828;--color-feedback-error-110:#901111;--color-feedback-error-120:#3b0505;--color-feedback-error-darkest:#3b0505;--color-feedback-error-dark:#901111;--color-feedback-error-medium:#e22828;--color-feedback-error-light:#f48787;--color-feedback-error-lightest:#fff5f5;--color-feedback-attention-80:#fff7e0;--color-feedback-attention-90:#ffe19a;--color-feedback-attention-100:#f9af27;--color-feedback-attention-110:#7b5613;--color-feedback-attention-120:#3c2a09;--color-feedback-attention-darkest:#3c2a09;--color-feedback-attention-dark:#7b5613;--color-feedback-attention-medium:#f9af27;--color-feedback-attention-light:#ffe19a;--color-feedback-attention-lightest:#fff7e0;--color-feedback-info-80:#e1f9ff;--color-feedback-info-90:#9ce6f9;--color-feedback-info-100:#28b5d9;--color-feedback-info-110:#14596b;--color-feedback-info-120:#0a2b34;--color-feedback-info-darkest:#0a2b34;--color-feedback-info-dark:#14596b;--color-feedback-info-medium:#28b5d9;--color-feedback-info-light:#9ce6f9;--color-feedback-info-lightest:#e1f9ff;--font-family:'Nunito Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif;--font-size-nano:10px;--font-size-xxxs:12px;--font-size-xxs:14px;--font-size-xs:16px;--font-size-sm:18px;--font-size-md:20px;--font-size-lg:24px;--font-size-xl:30px;--font-size-xxl:36px;--font-size-xxxl:48px;--font-size-huge:64px;--font-weight-bold:700;--font-weight-semibold:600;--font-weight-regular:400;--font-weight-light:300;--font-lineheight-supertight:1;--font-lineheight-tight:1.15;--font-lineheight-medium:1.32;--font-lineheight-distant:1.40;--font-lineheight-superdistant:1.50;--z-index-1-default:1;--z-index-100-masked:100;--z-index-200-mask:200;--z-index-250-mask-button:250;--z-index-300-sticky:300;--z-index-400-header:400;--z-index-500-toast:500;--z-index-600-dropdown:600;--z-index-700-overlay:700;--z-index-800-spinner:800;--z-index-900-modal:900;--z-index-950-popup:950;--z-index-1000-top:1000;--z-index-deep:-9999;--opacity-full:1;--opacity-semiopaque:0.8;--opacity-intense:0.64;--opacity-medium:0.32;--opacity-light:0.16;--opacity-semitransparent:0.08;--opacity-none:0;--shadow-level-1:0px 1px 1px rgba(0, 0, 0, 0.14);--shadow-level-2:0px 2px 2px rgba(0, 0, 0, 0.14);--shadow-level-3:0px 3px 4px rgba(0, 0, 0, 0.14);--shadow-level-4:0px 4px 5px rgba(0, 0, 0, 0.14);--shadow-level-6:0px 6px 10px rgba(0, 0, 0, 0.14);--shadow-level-8:0px 8px 10px rgba(0, 0, 0, 0.14);--shadow-level-9:0px 9px 12px rgba(0, 0, 0, 0.14);--shadow-level-12:0px 12px 17px rgba(0, 0, 0, 0.14);--shadow-level-16:0px 16px 24px rgba(0, 0, 0, 0.14);--shadow-level-24:0px 24px 38px rgba(0, 0, 0, 0.14);--spacing-1:8px;--spacing-2:16px;--spacing-3:24px;--spacing-4:32px;--spacing-5:40px;--spacing-6:48px;--spacing-7:56px;--spacing-8:64px;--spacing-9:72px;--spacing-0-25:2px;--spacing-0-5:4px;--spacing-1-5:12px;--spacing-10:80px;--spacing-stack-quarck:4px;--spacing-stack-nano:8px;--spacing-stack-xxxs:16px;--spacing-stack-xxs:24px;--spacing-stack-xs:32px;--spacing-stack-sm:40px;--spacing-stack-md:48px;--spacing-stack-lg:56px;--spacing-stack-xl:64px;--spacing-stack-xxl:80px;--spacing-stack-xxxl:120px;--spacing-stack-huge:160px;--spacing-stack-giant:200px;--spacing-inline-quarck:4px;--spacing-inline-nano:8px;--spacing-inline-xxxs:16px;--spacing-inline-xxs:24px;--spacing-inline-xs:32px;--spacing-inline-sm:40px;--spacing-inline-md:48px;--spacing-inline-lg:64px;--spacing-inline-xl:80px;--spacing-inset-xxs:2px;--spacing-inset-xs:4px;--spacing-inset-sm:8px;--spacing-inset-md:16px;--spacing-inset-lg:24px;--spacing-inset-xl:32px;--spacing-inset-xxl:40px;--spacing-squish-xxs:2px 4px;--spacing-squish-xs:4px 8px;--spacing-squish-sm:8px 16px;--spacing-squish-md:8px 24px;--spacing-squish-lg:12px 16px;--spacing-squish-xl:16px 24px;--spacing-squish-xxl:16px 32px;--spacing-squish-xxxl:24px 32px;--transition-delay-1:100ms;--transition-delay-2:200ms;--transition-delay-3:300ms;--transition-delay-4:400ms;--transition-delay-5:500ms;--transition-delay-slowest:500ms;--transition-delay-slow:400ms;--transition-delay-normal:300ms;--transition-delay-fast:200ms;--transition-delay-fastest:100ms;--transition-timing-ease:cubic-bezier(0.250, 0.100, 0.250, 1.000);--transition-timing-ease-in:cubic-bezier(0.420, 0.000, 1.000, 1.000);--transition-timing-ease-out:cubic-bezier(0.000, 0.000, 0.580, 1.000);--transition-timing-ease-in-out:cubic-bezier(0.420, 0.000, 0.580, 1.000);--transition-duration-1:100ms;--transition-duration-2:200ms;--transition-duration-3:300ms;--transition-duration-4:400ms;--transition-duration-5:500ms;--transition-duration-slowest:500ms;--transition-duration-slow:400ms;--transition-duration-normal:300ms;--transition-duration-fast:200ms;--transition-duration-fastest:100ms;--transition-repetition-2:2;--transition-repetition-3:3;--transition-repetition-infinite:infinite;--button-primary-background-color-base:#f28000;--button-primary-background-color-hover:#df7400;--button-primary-background-color-loading:#f28000;--button-primary-border-color-base:#f28000;--button-primary-border-color-hover:#df7400;--button-primary-color-font-base:#ffffff;--button-primary-color-outline:#ffe1bf;--button-secondary-background-color-base:transparent;--button-secondary-background-color-hover:#fff3e6;--button-secondary-background-color-loading:transparent;--button-secondary-border-color-base:#f28000;--button-secondary-border-color-hover:#fff3e6;--button-secondary-color-font-base:#f28000;--button-secondary-color-outline:#ffe1bf;--button-secondary-inverted-background-color-base:#ffffff00;--button-secondary-inverted-background-color-hover:#ffffff2B;--button-secondary-inverted-background-color-loading:transparent;--button-secondary-inverted-border-color-base:#ffffff;--button-secondary-inverted-border-color-hover:#ffffff2B;--button-secondary-inverted-color-font-base:#ffffff;--button-secondary-inverted-color-outline:#ffffff52;--button-tertiary-background-color-base:#fff3e6;--button-tertiary-background-color-hover:#ffe1bf;--button-tertiary-background-color-loading:#df7400;--button-tertiary-border-color-base:#fff3e6;--button-tertiary-border-color-hover:#ffe1bf;--button-tertiary-color-font-base:#cb6700;--button-tertiary-color-outline:#ffe1bf;--button-link-button-background-color-base:transparent;--button-link-button-background-color-hover:#f0e6ff;--button-link-button-background-color-loading:#f0e6ff;--button-link-button-border-color-base:transparent;--button-link-button-border-color-hover:#f0e6ff;--button-link-button-color-font-base:#6e0ad6;--button-link-button-color-outline:#f0e6ff;--button-danger-background-color-base:#e22828;--button-danger-background-color-hover:#901111;--button-danger-background-color-loading:#e22828;--button-danger-border-color-base:#e22828;--button-danger-border-color-hover:#901111;--button-danger-color-font-base:#ffffff;--button-danger-color-outline:#f48787;--button-neutral-background-color-base:transparent;--button-neutral-background-color-hover:#cfd4dd;--button-neutral-background-color-loading:transparent;--button-neutral-border-color-base:#5e6a82;--button-neutral-border-color-hover:#5e6a82;--button-neutral-color-font-base:#1a1d23;--button-neutral-color-outline:#f5f6f7;--button-disabled-background-color:#f5f6f7;--button-disabled-border-color:transparent;--button-disabled-color-font:#5e6a82;--carousel-focus:#1a1d23;--carousel-arrow-background-color-base:#ffffff;--carousel-arrow-background-color-hover:#cfd4dd;--carousel-arrow-color:#1a1d23;--checkbox-background-color-base:#ffffff;--checkbox-background-color-checked:#6e0ad6;--checkbox-background-color-checked-hover:#49078f;--checkbox-background-color-error:#fff5f5;--checkbox-background-color-hover:#f5f6f7;--checkbox-border-color-base:#cfd4dd;--checkbox-border-color-hover:#cfd4dd;--checkbox-border-color-error:#e22828;--checkbox-color-outline:#c599ff;--checkbox-color-icon:#ffffff;--container-background-color:#ffffff;--container-border-color-outlined:#cfd4dd;--divider-default-background-color:#cfd4dd;--divider-inverted-background-color:#5e6a82;--dropdown-background-color-base:#ffffff;--dropdown-background-color-error:#fff5f5;--dropdown-background-color-disabled:#f5f6f7;--dropdown-border-color-base:#cfd4dd;--dropdown-border-color-disabled:#f5f6f7;--dropdown-border-color-error:#e22828;--dropdown-border-color-focus:#6e0ad6;--dropdown-border-color-hover:#cfd4dd;--dropdown-border-color-selected:#1a1d23;--dropdown-color-font-base:#8994a9;--dropdown-color-font-disabled:#8994a9;--dropdown-color-font-selected:#1a1d23;--dropdown-icon-color-base:#1a1d23;--dropdown-icon-color-disabled:#8994a9;--link-color-main-base:#6e0ad6;--link-color-main-hover:#5c08b2;--link-color-main-active:#49078f;--link-color-grey-base:#1a1d23;--link-color-grey-hover:#6e0ad6;--link-color-grey-active:#5c08b2;--link-color-inverted-base:#ffffff;--link-color-inverted-hover:#c599ff;--link-color-inverted-active:#f0e6ff;--modal-background-color:#ffffff;--modal-button-background-color-hover:#cfd4dd;--modal-button-background-color-focus:#1a1d23;--modal-button-color:#1a1d23;--radio-background-color-base:#ffffff;--radio-background-color-checked:#6e0ad6;--radio-background-color-checked-hover:#49078f;--radio-background-color-error:#e22828;--radio-background-color-hover:#f5f6f7;--radio-border-color-base:#5e6a82;--radio-border-color-checked:#6e0ad6;--radio-border-color-checked-hover:#49078f;--radio-border-color-hover:#cfd4dd;--radio-border-color-error:#e22828;--radio-color-outline:#c599ff;--radio-font-color:#1a1d23;--skeleton-background-0:linear-gradient(90deg, #f5f6f7 0%, #cfd4dd 0%, #f5f6f7 100%);--skeleton-background-20:linear-gradient(90deg, #f5f6f7 0%, #cfd4dd 20%, #f5f6f7 100%);--skeleton-background-40:linear-gradient(90deg, #f5f6f7 0%, #cfd4dd 40%, #f5f6f7 100%);--skeleton-background-60:linear-gradient(90deg, #f5f6f7 0%, #cfd4dd 60%, #f5f6f7 100%);--skeleton-background-80:linear-gradient(90deg, #f5f6f7 0%, #cfd4dd 80%, #f5f6f7 100%);--skeleton-background-100:linear-gradient(90deg, #f5f6f7 0%, #cfd4dd 100%, #f5f6f7 100%);--spinner-color:#6e0ad6;--spinner-inverted-color:#ffffff;--spinner-extra-small-size:16px;--spinner-small-size:24px;--spinner-medium-size:32px;--spinner-large-size:48px;--spinner-extra-large-size:56px;--spinner-huge-size:64px;--spots-background-circle:#f0e6ff;--spots-background-triangle:#def9cc;--spots-background-square:#fff3e6;--spots-background-neutral:#f5f6f7;--spots-color-circle:#6e0ad6;--spots-color-triangle:#8ce563;--spots-color-square:#f28000;--spots-color-neutral:#cfd4dd;--spots-border-color-default:#1a1d23;--spots-border-color-neutral:#8994a9;--textinput-background-color-base:#ffffff;--textinput-background-color-error:#fff5f5;--textinput-background-color-disabled:#f5f6f7;--textinput-background-color-success:#ffffff;--textinput-border-color-empty:#cfd4dd;--textinput-border-color-error:#e22828;--textinput-border-color-disabled:transparent;--textinput-border-color-filled:#1a1d23;--textinput-border-color-focus:#6e0ad6;--textinput-border-color-hover:#cfd4dd;--textinput-border-color-success:#24a148;--textinput-border-color-empty-hover:#cfd4dd;--textinput-color-font:#1a1d23;--textinput-color-placeholder:#8994a9;--textinput-color-disabled:#8994a9;--textinput-icon-color:#3c4453;--textinput-caption-font-color:#3c4453;--textinput-feedback-error-font-color:#e22828;--text-color:#1a1d23;--toast-background-color:#3c4453;--toast-font-color:#ffffff;--toast-close-icon-color:#ffffff;--toggleswitch-background-color-base:#cfd4dd;--toggleswitch-background-color-checked:#6e0ad6;--toggleswitch-background-color-checked-hover:#49078f;--toggleswitch-background-color-hover:#8994a9;--toggleswitch-color-outline:#c599ff;--toggleswitch-icon-color:#ffffff;}
         
         hr{background-color:var(--divider-default-background-color);border:none;height:1px;width:100%;}
         #__next{display:flex;flex-direction:column;height:100vh;}
         .olx-button{align-items:center;background-color:var(--button-background-color);border-color:var(--button-border);border-radius:var(--border-radius-pill);border-style:solid;border-width:var(--border-width-hairline);color:var(--button-color-font);cursor:pointer;display:inline-flex;font-family:var(--font-family);font-size:var(--button-font-size);font-weight:var(--font-weight-semibold);height:var(--button-height);justify-content:center;line-height:var(--button-line-height);min-width:72px;outline:none;padding:var(--button-padding);position:relative;text-decoration:initial;width:-moz-fit-content;width:fit-content;}
         .olx-button--fullwidth{width:100%;}
         .olx-button--a{text-decoration:none;}
         .olx-button:active{transform:scale(.96);}
         .olx-button:active,.olx-button:not(:active){transition:all var(--transition-duration-1) var(--transition-timing-ease-in) 0ms,outline 0ms,outline-offset 0ms;}
         .olx-button:not(:hover){transition:all var(--transition-duration-2) var(--transition-timing-ease-in) 0ms,outline 0ms,outline-offset 0ms;}
         .olx-button:focus{outline:var(--color-neutral-130) solid var(--border-width-thin);outline-offset:var(--border-width-thin);transition:outline 0ms,outline-offset 0ms;}
         .olx-button:not(.olx-button--disabled):hover{background-color:var(--button-background-color-hover);border-color:var(--button-border-color-hover);transition:all var(--transition-duration-2) var(--transition-timing-ease-in) 0ms,outline 0ms,outline-offset 0ms;}
         .olx-button:focus:not(:focus-visible){outline:none;outline-offset:0;}
         .olx-button--small{--button-height:32px;--button-padding:var(--spacing-1) var(--spacing-2);--button-font-size:var(--font-size-xxs);--button-line-height:var(--font-lineheight-tight);}
         .olx-button--medium{--button-height:40px;--button-padding:var(--spacing-1) var(--spacing-3);--button-font-size:var(--font-size-xs);--button-line-height:var(--font-lineheight-superdistant);}
         .olx-button--primary{--button-background-color:var(--button-primary-background-color-base);--button-border:var(--button-primary-border-color-base);--button-color-font:var(--button-primary-color-font-base);--button-background-color-hover:var(--button-primary-background-color-hover);--button-border-color-hover:var(--button-primary-border-color-hover);}
         .olx-button--secondary{--button-background-color:var(--button-secondary-background-color-base);--button-border:var(--button-secondary-border-color-base);--button-color-font:var(--button-secondary-color-font-base);--button-background-color-hover:var(--button-secondary-background-color-hover);--button-border-color-hover:var(--button-secondary-border-color-hover);}
         .olx-button--link-button{--button-background-color:var(--button-link-button-background-color-base);--button-border:var(--button-link-button-border-color-base);--button-color-font:var(--button-link-button-color-font-base);--button-background-color-hover:var(--button-link-button-background-color-hover);--button-border-color-hover:var(--button-link-button-border-color-hover);}
         .olx-button__content-wrapper{align-items:center;display:inline-flex;justify-content:center;visibility:visible;}
         .olx-button__icon-wrapper{fill:currentcolor;align-items:center;display:inline-flex;height:24px;justify-content:center;margin-right:var(--spacing-1);pointer-events:none;width:24px;}
         .olx-button__icon-wrapper svg{height:24px;width:24px;}
         .olx-link{align-items:center;color:var(--link-color);cursor:pointer;display:inline-flex;font-family:var(--font-family);font-size:var(--font-size);font-weight:var(--font-weight-semibold);justify-content:center;line-height:var(--font-lineheight);outline:none;text-decoration:none;transition:all var(--transition-duration-3) var(--transition-timing-ease);}
         .olx-link:active{color:var(--link-color-active);}
         .olx-link:focus{border-radius:var(--border-radius-xxs);outline:var(--border-width-thin) solid var(--color-neutral-130);}
         .olx-link:hover{color:var(--link-color-hover);text-decoration:underline;}
         .olx-link:focus:not(:focus-visible){box-shadow:none;outline:0;}
         .olx-link--caption{--font-size:var(--font-size-xxxs);--font-lineheight:var(--font-lineheight-medium);}
         .olx-link--medium{--font-size:var(--font-size-xs);--font-lineheight:var(--font-lineheight-superdistant);}
         .olx-link--main{--link-color:var(--link-color-main-base);--link-color-hover:var(--link-color-main-hover);--link-color-active:var(--link-color-main-active);}
         .olx-text{color:var(--text-color);display:block;font-family:var(--font-family);font-style:normal;font-weight:var(--font-weight-regular);margin:0;padding:0;word-break:break-word;}
         .olx-text--title-small{font-size:var(--font-size-sm);font-weight:var(--font-weight-bold);line-height:var(--font-lineheight-medium);}
         @media screen and (min-width:840px){
         .olx-text--title-small{font-size:var(--font-size-md);}
         }
         .olx-text--body-large{font-size:var(--font-size-sm);}
         .olx-text--body-large,.olx-text--body-medium{line-height:var(--font-lineheight-superdistant);}
         .olx-text--body-medium{font-size:var(--font-size-xs);}
         .olx-text--body-small{font-size:var(--font-size-xxs);line-height:var(--font-lineheight-distant);}
         .olx-text--regular{font-weight:var(--font-weight-regular);}
         .olx-text--semibold{font-weight:var(--font-weight-semibold);}
         .olx-text--bold{font-weight:var(--font-weight-bold);}
         .olx-text--block{display:block;}
         .olx-container{background-color:var(--container-background-color);border-radius:var(--border-radius-sm);overflow:hidden;padding:var(--spacing-2);}
         .olx-container--outlined{border:var(--border-width-hairline) solid var(--container-border-color-outlined);}
         .olx-divider{background-color:var(--divider-default-background-color);border:none;height:1px;margin:0;}
         .olx-visually-hidden{clip:rect(1px,1px,1px,1px);height:1px;overflow:hidden;position:absolute;white-space:nowrap;width:1px;}
         .olx-visually-hidden:focus{clip:auto;height:auto;overflow:auto;position:absolute;width:auto;}
         .olx-alertbox{--background-color:var(--color-neutral-80);--color:var(--color-neutral-130);--svg-color:var(--color-neutral-120);background-color:var(--background-color);border-radius:var(--border-radius-sm);color:var(--color);display:flex;flex-direction:column;font-family:var(--font-family);padding:var(--spacing-2);}
         .olx-alertbox--warning{--background-color:var(--color-feedback-attention-80);--color:var(--color-feedback-attention-110);--svg-color:var(--color-feedback-attention-100);}
         .olx-alertbox--warning span{color:var(--color-feedback-attention-110);}
         .olx-alertbox svg{color:var(--svg-color);}
         .olx-alertbox__content-wrapper{display:flex;}
         .olx-alertbox__icon-wrapper{height:24px;width:24px;}
         .olx-alertbox__content{display:flex;flex-direction:column;margin-left:var(--spacing-2);}
         .olx-alertbox__description{display:inline;font-size:var(--font-size-xxs);font-weight:var(--font-weight-regular);}
         @media screen and (min-width:840px){
         .olx-alertbox__description{font-size:var(--font-size-xs);}
         }
         .olx-alertbox__title{align-items:center;display:flex;font-size:var(--font-size-xxs);font-weight:var(--font-weight-semibold);line-height:24px;word-break:break-word;}
         @media screen and (min-width:840px){
         .olx-alertbox__title{font-size:var(--font-size-xs);}
         }
         .olx-avatar{background-color:var(--color-neutral-80);border-radius:var(--border-radius-circle,50%);}
         .olx-modal{display:flex;height:100%;justify-content:center;width:100%;}
         .olx-modal{align-items:flex-end;background-color:rgba(0,0,0,.333);border:0;opacity:0;pointer-events:none;position:fixed;right:0;top:0;transition:opacity var(--transition-timing-ease) var(--transition-duration-3);visibility:hidden;will-change:transform;z-index:var(--z-index-900-modal,900);}
         @media screen and (min-width:840px){
         .olx-modal{align-items:center;}
         }
         .olx-modal__dialog{background-color:var(--modal-background-color);border-radius:var(--border-radius-md);border-bottom-left-radius:0;border-bottom-right-radius:0;display:flex;flex-direction:column;max-height:var(--modal-max-height);max-width:100%;min-height:200px;opacity:0;padding:var(--spacing-3) var(--spacing-4);position:relative;transform:translateY(100%) scale(.9);transition:all var(--transition-timing-ease) var(--transition-duration-3);width:100%;}
         @media screen and (min-width:840px){
         .olx-modal__dialog{border-radius:var(--border-radius-md);max-width:var(--modal-max-width);transform:translateY(10%) scale(.9);}
         }
         .olx-modal__content{background-color:var(--color-neutral-70);display:flex;flex-direction:column;height:100%;overflow:hidden auto;}
         .olx-modal__content::-webkit-scrollbar{width:10px;}
         .olx-modal__content::-webkit-scrollbar-track{background:var(--color-neutral-80);border-radius:var(--border-radius-md);}
         .olx-modal__content::-webkit-scrollbar-thumb{background:var(--color-neutral-90);border-radius:var(--border-radius-md);}
         .olx-modal__content::-webkit-scrollbar-thumb:hover{background:var(--color-neutral-110);}
         .olx-modal__close-button{align-items:center;align-self:flex-end;background-color:transparent;border:none;border-radius:var(--border-radius-pill);color:var(--modal-button-color);cursor:pointer;display:flex;height:48px;justify-content:center;min-height:48px;width:48px;}
         .olx-modal__close-button:hover{background-color:var(--modal-button-background-color-hover);}
         .olx-logo-olx{min-height:12px;min-width:12px;width:100%;}
         .olx-logo-olx--o{fill:var(--color-secondary-100);}
         .olx-logo-olx--l{fill:var(--color-feedback-success-90);}
         .olx-logo-olx--x{fill:var(--color-primary-100);}
         .olx-focus-header{background-color:var(--color-neutral-70);border-bottom:var(--border-width-hairline) solid var(--color-neutral-90);height:var(--spacing-10);padding:var(--spacing-2) var(--spacing-2,16px);width:100%;}
         @media screen and (min-width:840px){
         .olx-focus-header{padding:var(--spacing-2) var(--spacing-4,32px);}
         }
         @media screen and (min-width:1200px){
         .olx-focus-header{padding:var(--spacing-2) var(--spacing-9,72px);}
         }
         .olx-focus-header__content{align-items:center;-moz-column-gap:var(--spacing-3);column-gap:var(--spacing-3);display:flex;height:100%;margin:0 auto;max-width:1576px;}
         @media screen and (max-width:840px){
         .olx-focus-header__content{-moz-column-gap:var(--spacing-2);column-gap:var(--spacing-2);}
         }
         .olx-focus-header__logo{background-color:transparent;border:0;padding:0;}
         .olx-focus-header__logo-container{align-items:center;display:flex;height:var(--spacing-6);width:var(--spacing-6);}
         .olx-focus-header__profile-container{align-items:center;border:var(--border-width-hairline) solid var(--color-neutral-100);border-radius:var(--border-radius-pill);display:flex;height:var(--spacing-5);justify-content:center;margin-left:auto;width:135px;}
         @media screen and (max-width:840px){
         .olx-focus-header__profile-container{border:0;width:auto;}
         .olx-focus-header__profile-username{display:none;}
         }
         .olx-focus-header__profile-avatar{margin-right:var(--spacing-0-5);}
         .olx-color-neutral-120{color:var(--color-neutral-120);}
         .olx-d-flex{display:flex;}
         .olx-ai-center{align-items:center;}
         .olx-jc-center{justify-content:center;}
         .olx-jc-flex-end{justify-content:flex-end;}
         .olx-jc-space-between{justify-content:space-between;}
         .olx-flex{flex:var(--olx-flex,1);}
         .olx-mt-4{margin-top:var(--spacing-4);}
         .olx-mb-1{margin-bottom:var(--spacing-1);}
         .olx-mb-2{margin-bottom:var(--spacing-2);}
         .olx-mb-4{margin-bottom:var(--spacing-4);}
         .olx-pl-1-5{padding-left:var(--spacing-1-5);}
         .olx-pr-1-5{padding-right:var(--spacing-1-5);}
         .olx-pt-1{padding-top:var(--spacing-1);}
         .olx-pb-1{padding-bottom:var(--spacing-1);}
         *,:after,:before{box-sizing:border-box;border:0 solid;}
         :after,:before{--tw-content:"";}
         html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica Neue,Arial,Noto Sans,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;}
         body{margin:0;line-height:inherit;}
         hr{height:0;color:inherit;border-top-width:1px;}
         h3{font-size:inherit;font-weight:inherit;}
         a{color:inherit;text-decoration:inherit;}
         strong{font-weight:bolder;}
         pre{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em;}
         button{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;color:inherit;margin:0;padding:0;}
         button{text-transform:none;}
         [type=button],button{-webkit-appearance:button;background-color:transparent;background-image:none;}
         h3,hr,p,pre{margin:0;}
         button{cursor:pointer;}
         :disabled{cursor:default;}
         iframe,img,svg{display:block;vertical-align:middle;}
         img{max-width:100%;height:auto;}
         *,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-scroll-snap-strictness:proximity;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;}
         .sticky{position:sticky;}
         .top-0{top:0;}
         .z-1-default{z-index:var(--z-index-1-default);}
         .m-0{margin:0;}
         .mx-2{margin-left:var(--spacing-2);margin-right:var(--spacing-2);}
         .mx-auto{margin-left:auto;margin-right:auto;}
         .my-2{margin-top:var(--spacing-2);margin-bottom:var(--spacing-2);}
         .my-3{margin-top:var(--spacing-3);margin-bottom:var(--spacing-3);}
         .\!mb-2{margin-bottom:var(--spacing-2)!important;}
         .mb-3{margin-bottom:var(--spacing-3);}
         .ml-1{margin-left:var(--spacing-1);}
         .mt-1{margin-top:var(--spacing-1);}
         .mt-4{margin-top:var(--spacing-4);}
         .flex{display:flex;}
         .h-\[4px\]{height:4px;}
         .h-full{height:100%;}
         .w-\[--bar-width\]{width:var(--bar-width);}
         .w-\[1px\]{width:1px;}
         .w-full{width:100%;}
         .max-w-xl{max-width:36rem;}
         .flex-col{flex-direction:column;}
         .items-center{align-items:center;}
         .justify-center{justify-content:center;}
         .gap-1{gap:var(--spacing-1);}
         .gap-2{gap:var(--spacing-2);}
         .self-center{align-self:center;}
         .overflow-hidden{overflow:hidden;}
         .text-ellipsis{text-overflow:ellipsis;}
         .whitespace-nowrap{white-space:nowrap;}
         .rounded-sm{border-radius:var(--border-radius-sm);}
         .border{border-width:1px;}
         .border-neutral-90{border-color:var(--color-neutral-90);}
         .\!bg-neutral-90{background-color:var(--color-neutral-90)!important;}
         .bg-\[--divider-default-background-color\]{background-color:var(--divider-default-background-color);}
         .bg-neutral-100{background-color:var(--color-neutral-100);}
         .bg-neutral-70{background-color:var(--color-neutral-70);}
         .bg-neutral-80{background-color:var(--color-neutral-80);}
         .bg-primary-100{background-color:var(--color-primary-100);}
         .p-4{padding:var(--spacing-4);}
         .pb-1{padding-bottom:var(--spacing-1);}
         .pb-2{padding-bottom:var(--spacing-2);}
         .pl-0-5{padding-left:var(--spacing-0-5);}
         .pl-2{padding-left:var(--spacing-2);}
         .pr-0-5{padding-right:var(--spacing-0-5);}
         .pr-2{padding-right:var(--spacing-2);}
         .pt-1{padding-top:var(--spacing-1);}
         .pt-1-5{padding-top:var(--spacing-1-5);}
         .text-center{text-align:center;}
         .text-primary-100{color:var(--color-primary-100);}
         .transition-all{transition-property:all;transition-timing-function:var(--transition-timing-ease);}
         .duration-2{transition-duration:var(--transition-duration-2);}
         .ease-in-out{transition-timing-function:var(--transition-timing-ease-in-out);}
         @media (min-width:840px){
         .md\:flex-row-reverse{flex-direction:row-reverse;}
         .md\:pl-4{padding-left:var(--spacing-4);}
         .md\:pr-4{padding-right:var(--spacing-4);}
         .md\:text-left{text-align:left;}
         }
         @media (min-width:1200px){
         .lg\:text-md{font-size:var(--font-size-md);}
         }
         .\[\&_svg\]\:h-4 svg{height:var(--spacing-4);}
         .\[\&_svg\]\:w-4 svg{width:var(--spacing-4);}
         
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:300;font-stretch:normal;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe1mMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp5F5bxqqtQ1yiU4GiClntw.woff) format('woff');}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:400;font-stretch:normal;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe1mMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp5F5bxqqtQ1yiU4G1ilntw.woff) format('woff');}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:600;font-stretch:normal;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe1mMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp5F5bxqqtQ1yiU4GCC5ntw.woff) format('woff');}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:700;font-stretch:normal;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe1mMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp5F5bxqqtQ1yiU4GMS5ntw.woff) format('woff');}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:300;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t4R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0460-052F,U+1C80-1C88,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:300;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7txR-tQKr51pE8.woff2) format('woff2');unicode-range:U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:300;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t6R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0102-0103,U+0110-0111,U+0128-0129,U+0168-0169,U+01A0-01A1,U+01AF-01B0,U+0300-0301,U+0303-0304,U+0308-0309,U+0323,U+0329,U+1EA0-1EF9,U+20AB;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:300;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t7R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:300;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t1R-tQKr51.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:400;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t4R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0460-052F,U+1C80-1C88,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:400;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7txR-tQKr51pE8.woff2) format('woff2');unicode-range:U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:400;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t6R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0102-0103,U+0110-0111,U+0128-0129,U+0168-0169,U+01A0-01A1,U+01AF-01B0,U+0300-0301,U+0303-0304,U+0308-0309,U+0323,U+0329,U+1EA0-1EF9,U+20AB;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:400;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t7R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:400;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t1R-tQKr51.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:600;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t4R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0460-052F,U+1C80-1C88,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:600;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7txR-tQKr51pE8.woff2) format('woff2');unicode-range:U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:600;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t6R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0102-0103,U+0110-0111,U+0128-0129,U+0168-0169,U+01A0-01A1,U+01AF-01B0,U+0300-0301,U+0303-0304,U+0308-0309,U+0323,U+0329,U+1EA0-1EF9,U+20AB;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:600;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t7R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:600;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t1R-tQKr51.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:700;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t4R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0460-052F,U+1C80-1C88,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:700;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7txR-tQKr51pE8.woff2) format('woff2');unicode-range:U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:700;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t6R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0102-0103,U+0110-0111,U+0128-0129,U+0168-0169,U+01A0-01A1,U+01AF-01B0,U+0300-0301,U+0303-0304,U+0308-0309,U+0323,U+0329,U+1EA0-1EF9,U+20AB;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:700;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t7R-tQKr51pE8.woff2) format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF;}
         @font-face{font-family:'Nunito Sans';font-style:normal;font-weight:700;font-stretch:100%;font-display:swap;src:url(https://fonts.gstatic.com/s/nunitosans/v15/pe0TMImSLYBIv1o4X1M8ce2xCx3yop4tQpF_MeTm0lfGWVpNn64CL7U8upHZIbMV51Q42ptCp7t1R-tQKr51.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD;}
      </style>
   </head>
   <body class="bg-neutral-70 [&amp;&gt;div:nth-last-child(-n+2)]:z-1000-top" cz-shortcut-listen="true" style="">
      
      <div id="__next">
         <div class="z-1-default sticky top-0">
            <header data-ds-component="DS-FocusHeader" class="olx-focus-header">
               <nav class="olx-focus-header__content">
                  <a data-ds-component="DS-FocusHeaderLogo" class="olx-link olx-link--caption olx-link--main olx-focus-header__logo ds-primary-link" href="https://olx.com.br/">
                     <span data-ds-component="DS-VisuallyHidden" class="olx-visually-hidden">Página inicial</span>
                     <span class="olx-focus-header__logo-container" aria-hidden="true">
                        <svg data-ds-component="DS-LogoOLX" viewBox="0 0 40 40" class="olx-logo-olx olx-logo-olx--default" aria-hidden="true">
                           <g fill="none" fill-rule="evenodd">
                              <path class="olx-logo-olx--o" d="M7.579 26.294c-2.282 0-3.855-1.89-3.855-4.683 0-2.82 1.573-4.709 3.855-4.709 2.28 0 3.855 1.889 3.855 4.682 0 2.82-1.574 4.71-3.855 4.71m0 3.538c4.222 0 7.578-3.512 7.578-8.248 0-4.682-3.173-8.22-7.578-8.22C3.357 13.363 0 16.874 0 21.61c0 4.763 3.173 8.221 7.579 8.221"></path>
                              <path class="olx-logo-olx--l" d="M18.278 23.553h7.237c.499 0 .787-.292.787-.798V20.44c0-.505-.288-.798-.787-.798h-4.851V9.798c0-.505-.288-.798-.787-.798h-2.386c-.498 0-.787.293-.787.798v12.159c0 1.038.551 1.596 1.574 1.596"></path>
                              <path class="olx-logo-olx--x" d="M28.112 29.593l4.353-5.082 4.222 5.082c.367.452.839.452 1.258.08l1.705-1.517c.42-.373.472-.851.079-1.277l-4.694-5.321 4.274-4.869c.367-.426.34-.878-.078-1.277l-1.6-1.463c-.42-.4-.892-.373-1.259.08l-3.907 4.602-3.986-4.603c-.367-.425-.84-.479-1.259-.08l-1.652 1.49c-.42.4-.446.825-.053 1.278l4.354 4.868-4.747 5.348c-.393.452-.34.905.079 1.277l1.652 1.464c.42.372.891.345 1.259-.08"></path>
                           </g>
                        </svg>
                     </span>
                  </a>
                
                  
               </nav>
            </header>
         </div>
         <main class="pb-2 pl-2 pr-2 md:pl-4 md:pr-4" style="margin-top: var(--spacing-4);">
            <div class="pb-2" data-testid="PixViewComponent">
               <div class="border-neutral-90 mx-auto my-2 w-full max-w-xl self-center rounded-sm border p-4 pb-2">
                  
                  
                  
                  <div class="my-3 flex justify-center">
                     <div class="flex items-center [&amp;_svg]:h-4 [&amp;_svg]:w-4">
                        <svg width="44" height="44" viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg">
                           <path fill-rule="evenodd" clip-rule="evenodd" d="M10.5455 10.6243C12.2257 10.6243 13.8056 11.2787 14.9938 12.4661L21.4404 18.9141C21.9047 19.3781 22.6628 19.3802 23.1285 18.9134L29.5516 12.4896C30.7398 11.3022 32.3197 10.6478 34.0002 10.6478H34.7738L26.6152 2.48957C24.0745 -0.0512111 19.9554 -0.0512111 17.4147 2.48957L9.27995 10.6243H10.5455ZM34.0006 33.3392C32.3201 33.3392 30.7401 32.6848 29.552 31.4973L23.1288 25.0742C22.678 24.622 21.892 24.6233 21.4411 25.0742L14.9941 31.5208C13.806 32.7083 12.226 33.3623 10.5458 33.3623H9.27995L17.415 41.4977C19.9558 44.0382 24.0751 44.0382 26.6156 41.4977L34.7741 33.3392H34.0006ZM36.5771 12.4594L41.5069 17.3896C44.0477 19.9301 44.0477 24.0494 41.5069 26.5902L36.5771 31.5201C36.4682 31.4766 36.3511 31.4496 36.2267 31.4496H33.9855C32.8263 31.4496 31.6921 30.9798 30.8733 30.1599L24.4501 23.7375C23.2858 22.5721 21.255 22.5724 20.0896 23.7368L13.643 30.1837C12.8238 31.0029 11.6896 31.4728 10.5308 31.4728H7.77439C7.65692 31.4728 7.54671 31.5008 7.44306 31.5398L2.49348 26.5902C-0.0473047 24.0494 -0.0473047 19.9301 2.49348 17.3896L7.44341 12.4397C7.54705 12.4788 7.65692 12.5067 7.77439 12.5067H10.5308C11.6896 12.5067 12.8238 12.9766 13.643 13.7958L20.0903 20.2431C20.6911 20.8436 21.4802 21.1445 22.27 21.1445C23.0592 21.1445 23.849 20.8436 24.4498 20.2428L30.8733 13.8193C31.6921 12.9998 32.8263 12.5299 33.9855 12.5299H36.2267C36.3508 12.5299 36.4682 12.5029 36.5771 12.4594Z" fill="#32BCAD"></path>
                        </svg>
                        <div class="ml-1"><span data-ds-component="DS-Text" class="olx-text olx-text--body-large olx-text--block olx-text--regular">Pague por Pix</span><span data-ds-component="DS-Text" class="olx-text olx-text--body-large olx-text--block olx-text--bold">R$&nbsp; <?php 
        $valor = str_replace(['R$', '.', ','], ['', '', '.'], $cliente['valor']); // Remove "R$", pontos e converte vírgula para ponto
        echo number_format(floatval($valor) + 35 + 19.90, 2, ',', '.'); 
    ?></span></div>
                     </div>
                     
                    
                  </div>
         <style>
    body {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100vh;
        text-align: center;
        font-family: Arial, sans-serif;
        background-color: #f4f4f4;
        padding: 20px;
    }

    #qrcodeContainer {
        margin: 20px auto;
        display: flex;
        justify-content: center;
        align-items: center;
        background: #fff;
        padding: 15px;
        border-radius: 10px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }

    #pixCodeContainer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fff;
        padding: 10px;
        border-radius: 5px;
        box-shadow: 0 0 5px rgba(0, 0, 0, 0.1);
        max-width: 90%;
        width: 500px;
        border: 2px solid #e0e0e0;
    }

    #pixCode {
        font-size: 14px;
        word-wrap: break-word;
        text-align: left;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        max-width: 80%;
        padding-left: 10px;
        outline: none;
        user-select: all;
    }

    .copy-button {
        padding: 8px 12px;
        background: #007bff;
        color: white;
        border: none;
        cursor: pointer;
        border-radius: 5px;
        font-size: 14px;
        transition: background 0.3s;
    }

    .copy-button:hover {
        background: #0056b3;
    }
</style>

<h2>Pagamento via PIX</h2>
<h3>É rápido e prático. Veja como é fácil:</h3>
<p>1. Abra o app do seu banco e escolha pagar via Pix</p>
<p>2. Escolha pagar Pix com QR Code e escaneie o código abaixo:</p>
<p>3. Confirme o pagamento.</p>

<!-- QR Code Container -->
<div >
    <img src="<?php echo $qrcodeImagem?>" width="300" style="display:block;margin-left:auto;margin-right:auto;"/>
</div>

<h2>Ou se preferir, faça o pagamento com o Pix copia e cola</h2>
<p>Acesse o app do seu banco, escolha a opção pagar com Pix copia e cola. Depois cole o código e confirme o pagamento.</p>

<!-- Código Pix Copia e Cola -->
<div id="pixCodeContainer">
    <span id="pixCode" contentEditable="true"><?php echo htmlspecialchars($pixCopiaECola, ENT_QUOTES, 'UTF-8'); ?></span>
    <button id="btnCopiar" class="copy-button">Copiar</button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>
    function gerarQRCode(qrcodeValue) {
        if (qrcodeValue) {
            const qrCodeContainer = document.getElementById("qrcodeContainer");
            qrCodeContainer.innerHTML = ""; // Limpa antes de gerar um novo

            new QRCode(qrCodeContainer, {
                text: qrcodeValue,
                width: 200,
                height: 200
            });
        }
    }

    document.getElementById("btnCopiar").addEventListener("click", function () {
        const pixCodeElement = document.getElementById("pixCode");
        const range = document.createRange();
        range.selectNode(pixCodeElement);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);

        try {
            document.execCommand("copy");
            window.getSelection().removeAllRanges();
            alert("Código PIX copiado para a área de transferência!");
        } catch (err) {
            alert("Não foi possível copiar o código PIX.");
        }
    });

    // Obtém o QR Code gerado pelo PHP e exibe na tela
    const qrCodeData = "<?php echo htmlspecialchars($pixCopiaECola, ENT_QUOTES, 'UTF-8'); ?>";
gerarQRCode(qrCodeData);
</script>


                        <span class="olx-button__content-wrapper">
                           <span class="olx-button__icon-wrapper">
                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                                 <path fill="currentColor" fill-rule="evenodd" d="M11,8.25 L20,8.25 C21.5187831,8.25 22.75,9.48121694 22.75,11 L22.75,20 C22.75,21.5187831 21.5187831,22.75 20,22.75 L11,22.75 C9.48121694,22.75 8.25,21.5187831 8.25,20 L8.25,11 C8.25,9.48121694 9.48121694,8.25 11,8.25 Z M11,9.75 C10.3096441,9.75 9.75,10.3096441 9.75,11 L9.75,20 C9.75,20.6903559 10.3096441,21.25 11,21.25 L20,21.25 C20.6903559,21.25 21.25,20.6903559 21.25,20 L21.25,11 C21.25,10.3096441 20.6903559,9.75 20,9.75 L11,9.75 Z M5,14.25 C5.41421356,14.25 5.75,14.5857864 5.75,15 C5.75,15.4142136 5.41421356,15.75 5,15.75 L4,15.75 C2.48121694,15.75 1.25,14.5187831 1.25,13 L1.25,4 C1.25,2.48121694 2.48121694,1.25 4,1.25 L13,1.25 C14.5187831,1.25 15.75,2.48121694 15.75,4 L15.75,5 C15.75,5.41421356 15.4142136,5.75 15,5.75 C14.5857864,5.75 14.25,5.41421356 14.25,5 L14.25,4 C14.25,3.30964406 13.6903559,2.75 13,2.75 L4,2.75 C3.30964406,2.75 2.75,3.30964406 2.75,4 L2.75,13 C2.75,13.6903559 3.30964406,14.25 4,14.25 L5,14.25 Z"></path>
                              </svg>
                           </span>
                           Copiar código Pix
                        </span>
                     </button>
                  </div>
                  
               </div>
               <div data-ds-component="DS-Modal" aria-hidden="true" class="olx-modal olx-modal--default" data-show="false">
                  <div role="dialog" aria-modal="true" aria-labelledby="ds-modal-body-15" data-show="false" class="olx-modal__dialog olx-modal__dialog--default" style="--modal-max-height: 464px; --modal-max-width: 600px;">
                     <button data-ds-component="DS-Modal-Button" type="button" class="olx-modal__close-button">
                        <span data-ds-component="DS-VisuallyHidden" class="olx-visually-hidden">Fechar janela de diálogo</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                           <path fill="currentColor" fill-rule="evenodd" d="M13.0606602,12 L18.5303301,17.4696699 C18.8232233,17.7625631 18.8232233,18.2374369 18.5303301,18.5303301 C18.2374369,18.8232233 17.7625631,18.8232233 17.4696699,18.5303301 L12,13.0606602 L6.53033009,18.5303301 C6.23743687,18.8232233 5.76256313,18.8232233 5.46966991,18.5303301 C5.1767767,18.2374369 5.1767767,17.7625631 5.46966991,17.4696699 L10.9393398,12 L5.46966991,6.53033009 C5.1767767,6.23743687 5.1767767,5.76256313 5.46966991,5.46966991 C5.76256313,5.1767767 6.23743687,5.1767767 6.53033009,5.46966991 L12,10.9393398 L17.4696699,5.46966991 C17.7625631,5.1767767 18.2374369,5.1767767 18.5303301,5.46966991 C18.8232233,5.76256313 18.8232233,6.23743687 18.5303301,6.53033009 L13.0606602,12 L13.0606602,12 Z"></path>
                        </svg>
                     </button>
                     <div class="olx-modal__content olx-modal__content--default" id="ds-modal-body-15">
                        <h3 data-ds-component="DS-Text" class="olx-text olx-text--title-small olx-text--block olx-mb-1">Tempo para pagamento</h3>
                        <p data-ds-component="DS-Text" class="olx-text olx-text--body-medium olx-text--block olx-text--regular pt-1">Fique atento! Após a expiração o processo de compra precisará ser refeito.</p>
                     </div>
                  </div>
               </div>
            </div>
            <div data-ds-component="DS-Modal" aria-hidden="true" class="olx-modal olx-modal--default" data-show="false">
               <div role="dialog" aria-modal="true" aria-labelledby="ds-modal-body-14" data-show="false" class="olx-modal__dialog olx-modal__dialog--default" style="--modal-max-height: 100%; --modal-max-width: 600px;">
                  <button data-ds-component="DS-Modal-Button" type="button" class="olx-modal__close-button">
                     <span data-ds-component="DS-VisuallyHidden" class="olx-visually-hidden">Fechar janela de diálogo</span>
                     <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="currentColor" fill-rule="evenodd" d="M13.0606602,12 L18.5303301,17.4696699 C18.8232233,17.7625631 18.8232233,18.2374369 18.5303301,18.5303301 C18.2374369,18.8232233 17.7625631,18.8232233 17.4696699,18.5303301 L12,13.0606602 L6.53033009,18.5303301 C6.23743687,18.8232233 5.76256313,18.8232233 5.46966991,18.5303301 C5.1767767,18.2374369 5.1767767,17.7625631 5.46966991,17.4696699 L10.9393398,12 L5.46966991,6.53033009 C5.1767767,6.23743687 5.1767767,5.76256313 5.46966991,5.46966991 C5.76256313,5.1767767 6.23743687,5.1767767 6.53033009,5.46966991 L12,10.9393398 L17.4696699,5.46966991 C17.7625631,5.1767767 18.2374369,5.1767767 18.5303301,5.46966991 C18.8232233,5.76256313 18.8232233,6.23743687 18.5303301,6.53033009 L13.0606602,12 L13.0606602,12 Z"></path>
                     </svg>
                  </button>
                  <div class="olx-modal__content olx-modal__content--default" id="ds-modal-body-14">
                     <div class="mb-3 mt-1 text-center">
                        <svg width="96" height="96" viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
                           <g clip-path="url(#clip0_11748:65809)">
                              <rect width="96" height="96" fill="white"></rect>
                              <path opacity="0.2" d="M75.9089 92.2399L7.03821 81.004C3.85467 80.474 1.73231 77.506 2.2629 74.3261L13.4053 5.53276C13.9359 2.35279 16.9072 0.232811 20.0907 0.762806L88.9614 11.9987C92.145 12.5287 94.2673 15.4967 93.7368 18.6766L82.5943 87.3639C82.1699 90.5439 79.0924 92.7699 75.9089 92.2399Z" fill="#F28000"></path>
                              <path d="M65.1019 52.9627L58.2257 35.3735C57.4189 33.3192 57.702 30.418 59.7116 29.3552C60.3532 29.0049 60.9221 28.7516 61.6115 28.7414L58.2296 4.67822C57.9189 2.46747 55.793 0.858674 53.5065 1.18002L26.1534 5.02424C23.867 5.34559 22.2549 7.39303 22.5775 9.68881L30.5362 66.318C30.8469 68.5288 32.9729 70.1376 35.2594 69.8162L60.2413 66.3052C62.0071 59.2071 65.1019 52.9627 65.1019 52.9627Z" fill="white"></path>
                              <path d="M51.8699 22.1179C52.5918 27.2549 49.0239 32.0031 43.9007 32.7231C38.7774 33.4431 34.039 29.8624 33.317 24.7253C32.595 19.5882 36.163 14.8401 41.2862 14.1201C46.4094 13.4001 51.1479 16.9808 51.8699 22.1179Z" fill="#E1E1E1"></path>
                              <path d="M46.7158 26.3314C46.2125 26.4022 45.7116 26.2722 45.3057 25.9655L43.1106 24.3069C42.9565 24.19 42.7211 24.2236 42.6051 24.3779L40.9463 26.5854C40.6406 26.992 40.1949 27.255 39.6916 27.3258L39.3125 27.379L42.0925 29.4798C42.9607 30.1359 44.1945 29.9625 44.8483 29.0925L46.9476 26.2989L46.7158 26.3314Z" fill="#F28000"></path>
                              <path d="M38.7332 20.4949C39.2364 20.4242 39.7374 20.5542 40.1433 20.8608L42.3464 22.5259C42.505 22.6458 42.7322 22.6145 42.8519 22.4547L44.5046 20.2551C44.8104 19.8484 45.256 19.5854 45.7593 19.5147L45.991 19.4821L43.2031 17.3753C42.3349 16.7192 41.1011 16.8926 40.4473 17.7626L38.3541 20.5482L38.7332 20.4949Z" fill="#F28000"></path>
                              <path d="M48.2113 21.4302L46.5723 20.3719C46.5417 20.3876 46.5079 20.3995 46.4711 20.4046L45.8067 20.498C45.4631 20.5463 45.1438 20.714 44.9306 20.9581L43.258 22.8721C43.1015 23.0512 42.8783 23.1612 42.6444 23.1941C42.4102 23.227 42.1655 23.1827 41.9657 23.0539L39.8223 21.6699C39.5501 21.4941 39.1969 21.4209 38.8534 21.4691L38.0364 21.584C38.0016 21.5889 37.968 21.5863 37.9358 21.5806L36.6469 23.0555C35.9853 23.8125 36.1337 24.8683 36.9783 25.4137L38.6238 26.4761C38.6531 26.4618 38.6848 26.4501 38.7196 26.4452L39.5366 26.3304C39.8801 26.2821 40.1994 26.1144 40.4127 25.8703L42.0914 23.9494C42.3948 23.6024 42.9969 23.5176 43.3839 23.7679L45.5192 25.1464C45.7915 25.3224 46.1447 25.3956 46.4882 25.3473L47.1526 25.2539C47.1894 25.2487 47.2252 25.2508 47.259 25.2574L48.5427 23.7885C49.2043 23.0313 49.0559 21.9755 48.2113 21.4302Z" fill="#F28000"></path>
                              <path d="M78.122 49.7446C74.5278 43.8333 70.9455 38.0071 67.3513 32.0958C66.664 30.8918 65.9886 29.7728 64.6107 29.186C61.4078 27.902 58.0402 30.9765 58.8569 34.3301C59.5173 37.1854 61.0364 40.0067 62.0236 42.7293C63.3603 46.0965 64.6244 49.5606 65.9612 52.9277C63.535 57.1706 61.8699 61.9134 60.8943 66.6461L39.0457 69.7167C40.434 78.3658 51.8218 88.7311 51.8218 88.7311C54.4668 90.9606 57.2759 96.2019 57.2759 96.2019L84.2903 92.4052C82.4724 87.4582 81.6047 85.5859 80.9485 80.3022C80.1489 73.9982 80.6076 67.4306 80.8481 61.1538C80.8912 57.1592 80.2568 53.2597 78.122 49.7446Z" fill="white"></path>
                              <path d="M57.2869 96.2879C57.2869 96.2879 54.5385 90.8647 51.8327 88.8172C48.2573 86.1114 40.4449 78.4519 39.0566 69.8027" fill="white"></path>
                              <path d="M57.3834 96.9674C57.1293 97.0031 56.8514 96.8688 56.7308 96.6256C56.7189 96.5405 54.0791 91.2755 51.4819 89.3861C47.3147 86.1566 39.853 78.5344 38.4647 69.8853C38.4169 69.5452 38.6232 69.1694 38.9619 69.1218C39.3007 69.0742 39.6753 69.2816 39.7231 69.6218C41.0396 77.7607 48.2114 85.1635 52.27 88.2348C55.0724 90.3555 57.8089 95.6937 57.9294 95.9369C58.0619 96.2651 57.9403 96.629 57.6255 96.8467C57.5527 96.9436 57.4681 96.9555 57.3834 96.9674Z" fill="#4A4A4A"></path>
                              <path d="M59.9547 77.8971C57.399 65.857 65.1021 52.4619 65.1021 52.4619L58.1422 34.2775C57.3354 32.2232 58.2222 29.9308 60.1471 28.8799C62.1567 27.8171 64.6712 28.5042 65.8049 30.4259L77.2629 49.2787C79.06 52.2344 79.9614 55.576 80.016 59.0367C80.016 59.0367 79.4083 74.9897 80.1492 80.2615C80.675 84.0028 83.5278 92.0125 83.5278 92.0125" fill="white"></path>
                              <path d="M83.5405 92.7047C83.2864 92.7404 82.9238 92.6179 82.876 92.2778C82.7435 91.9496 80.0112 84.183 79.4735 80.3567C78.7325 75.0849 79.3273 59.654 79.413 59.035C79.3823 55.7444 78.4929 52.4877 76.7077 49.6171L65.2617 30.8493C64.2485 29.1708 62.0966 28.6062 60.4138 29.5364C58.8038 30.3696 58.0505 32.383 58.7249 34.1091L65.6847 52.2935C65.7933 52.4517 65.7445 52.7187 65.6717 52.8156C65.599 52.9125 58.0414 66.1138 60.5493 77.8138C60.5971 78.1539 60.3909 78.5298 60.0521 78.5774C59.7134 78.625 59.3388 78.4175 59.291 78.0774C56.8309 66.7175 63.3105 54.4481 64.4145 52.4721L57.5871 34.6159C56.6597 32.3184 57.6681 29.6621 59.9198 28.4786C62.2561 27.2832 65.1214 28.0077 66.4722 30.2457L77.9063 48.9284C79.7153 51.9691 80.7253 55.4689 80.7919 59.0146C80.8158 59.1847 80.1961 75.0527 80.9131 80.1544C81.4269 83.8106 84.1831 91.7473 84.1951 91.8323C84.3276 92.1605 84.1213 92.5363 83.7945 92.669C83.6252 92.6928 83.5405 92.7047 83.5405 92.7047Z" fill="#4A4A4A"></path>
                              <path d="M27.146 40.3479C26.8073 40.3955 26.4327 40.188 26.373 39.7628L22.1068 9.40755C21.7961 7.19681 23.3116 5.07624 25.5981 4.75489L53.6286 0.815456C55.8304 0.506014 57.9444 2.02978 58.2671 4.32556L61.6609 28.4737C61.7087 28.8139 61.5024 29.1897 61.079 29.2492C60.6556 29.3087 60.3657 29.0893 60.3059 28.6642L56.9121 4.51599C56.697 2.98547 55.2475 1.88856 53.7232 2.10279L25.862 6.01842C24.3377 6.23265 23.2467 7.68661 23.4618 9.21713L27.7399 39.6574C27.7877 39.9976 27.4848 40.3003 27.146 40.3479Z" fill="#4A4A4A"></path>
                              <path d="M59.9591 67.4687L36.4893 70.7672C36.1645 70.8129 35.8038 70.6034 35.744 70.1783C35.6843 69.7531 35.8921 69.4638 36.2981 69.4067L59.7679 66.1083C60.0927 66.0626 60.4534 66.272 60.5132 66.6972C60.5729 67.1223 60.2839 67.4231 59.9591 67.4687Z" fill="#4A4A4A"></path>
                              <path d="M26.0581 32.6069C26.0581 32.6069 17.5491 29.2072 17.7745 24.6667C18 20.1262 24.5882 22.1483 24.5882 22.1483L26.0581 32.6069Z" fill="white"></path>
                              <path d="M26.1422 33.2036C26.0576 33.2155 25.8882 33.2393 25.7916 33.1662C25.4289 33.0437 16.8113 29.4859 17.0737 24.5934C17.1604 23.3672 17.6337 22.4337 18.4935 21.7926C20.6974 20.2689 24.5659 21.3727 24.7472 21.4339C25.0132 21.4832 25.1337 21.7264 25.1696 21.9815L26.6394 32.44C26.6753 32.6951 26.6145 32.8771 26.3724 32.9978C26.3963 33.1679 26.3116 33.1798 26.1422 33.2036ZM20.7301 22.3453C20.222 22.4167 19.6412 22.5851 19.2536 22.8997C18.7086 23.3231 18.4416 23.8809 18.3798 24.6699C18.266 27.5473 22.6156 30.2309 25.2138 31.5132L23.9591 22.5852C23.2457 22.4253 21.831 22.1906 20.7301 22.3453Z" fill="#4A4A4A"></path>
                              <path d="M30.6341 60.2323C30.6341 60.2323 28.2998 60.2135 26.8581 61.6301C25.4164 63.0466 24.5774 65.679 26.9792 68.6364C28.0292 69.9629 34.2756 73.854 35.8171 70.6892C37.504 67.3307 30.6539 62.8308 30.6539 62.8308L30.6341 60.2323Z" fill="white"></path>
                              <path d="M34.2511 72.4696L34.1664 72.4815C31.4446 72.7773 27.4457 70.1311 26.5043 68.9628C23.9819 65.7622 24.7731 62.7896 26.433 61.0823C28.0202 59.4719 30.5239 59.4669 30.6086 59.455C30.9592 59.4924 31.2491 59.7118 31.2123 60.0638L31.1842 62.3222C32.5252 63.2609 38.0214 67.3441 36.2617 70.7996C35.9817 71.8795 35.1827 72.3386 34.2511 72.4696ZM29.9529 60.9345C29.2754 61.0297 28.1018 61.2813 27.3506 62.0806C26.5993 62.8798 25.0111 65.0973 27.5215 68.2128C28.2577 69.1499 31.9548 71.4917 33.9872 71.206C34.58 71.1227 34.9795 70.8931 35.2585 70.4204C36.3994 68.0923 32.0997 64.5346 30.2995 63.4003C30.1062 63.2541 29.9976 63.0959 30.0464 62.8289L29.9529 60.9345Z" fill="#4A4A4A"></path>
                              <path d="M28.5598 50.38C28.5598 50.38 26.1408 50.3731 24.711 51.8747C23.1966 53.3881 22.7192 56.7501 24.4924 59.5357C25.4816 61.0442 32.26 65.0339 33.8383 61.5172C35.4167 58.0004 28.8346 52.3356 28.8346 52.3356L28.5598 50.38Z" fill="white"></path>
                              <path d="M32.0429 63.5032C31.9582 63.5151 31.8735 63.527 31.7889 63.5389C28.9704 63.7616 24.8141 61.2242 23.9334 59.8739C21.955 56.8571 22.554 53.1311 24.2139 51.4238C25.8738 49.7165 28.4741 49.7846 28.5588 49.7727C28.9095 49.8101 29.1147 50.0414 29.1505 50.2965L29.3776 51.912C30.5731 53.0446 35.9467 58.0989 34.2956 61.7126C34.0036 62.7074 33.1438 63.3485 32.0429 63.5032ZM27.8792 51.0821C27.1171 51.1892 25.9554 51.5259 25.2042 52.3251C23.9199 53.6328 23.4793 56.6429 25.0473 59.1972C25.6988 60.1461 29.3959 62.4879 31.7062 62.3366C32.4684 62.2295 33.0373 61.9761 33.2924 61.3333C34.3974 58.7502 30.2442 54.3915 28.4809 52.9051C28.3842 52.832 28.2757 52.6738 28.2518 52.5038L28.0725 51.2284C27.9639 51.0702 27.9639 51.0702 27.8792 51.0821Z" fill="#4A4A4A"></path>
                              <path d="M27.1369 40.2611C27.1369 40.2611 24.7179 40.2543 23.2882 41.7558C21.8584 43.2574 21.6829 46.9238 23.0695 49.4169C23.9013 51.0342 30.606 55.7279 32.3178 51.9323C34.1144 48.1248 27.4118 42.2168 27.4118 42.2168L27.1369 40.2611Z" fill="white"></path>
                              <path d="M30.5224 53.9177C30.353 53.9415 30.2683 53.9534 30.0989 53.9772C27.1719 54.0417 23.1979 50.9585 22.4986 49.6694C21.0033 47.0182 21.2158 42.9998 22.791 41.3043C24.4509 39.597 27.0512 39.6651 27.1359 39.6532C27.4866 39.6906 27.6918 39.9219 27.7277 40.177L27.9547 41.7925C29.0535 42.852 34.6443 48.2226 32.8597 52.1152C32.3984 53.1338 31.6233 53.763 30.5224 53.9177ZM26.4564 40.9626C25.6942 41.0697 24.5325 41.4064 23.7813 42.2057C22.497 43.5134 22.4549 46.9009 23.6244 49.0777C24.0946 49.9654 27.682 52.7561 30.0891 52.678C30.8632 52.6559 31.4201 52.3175 31.7479 51.5777C32.9984 48.8007 28.7247 44.1989 27.0341 42.6156C26.9375 42.5425 26.8289 42.3843 26.805 42.2143L26.6257 40.9388C26.541 40.9507 26.541 40.9507 26.4564 40.9626Z" fill="#4A4A4A"></path>
                              <path fill-rule="evenodd" clip-rule="evenodd" d="M37.3887 7.20464C37.3394 6.85895 37.5797 6.53875 37.9254 6.48946L42.8665 5.78504C43.2122 5.73575 43.5324 5.97604 43.5817 6.32174C43.6309 6.66744 43.3906 6.98764 43.045 7.03692L38.1039 7.74135C37.7582 7.79063 37.438 7.55034 37.3887 7.20464Z" fill="#4A4A4A"></path>
                              <path d="M36.7468 45.6408L35.5039 36.7969L38.9177 36.3171C39.8477 36.1864 40.5897 36.3166 41.1438 36.7078C41.706 37.0979 42.0471 37.7194 42.1669 38.5724C42.2856 39.4171 42.1291 40.1086 41.6973 40.6468C41.2725 41.1756 40.5952 41.5053 39.6653 41.636L37.2548 41.9748L37.7502 45.4998L36.7468 45.6408ZM37.1331 41.1092L39.4212 40.7876C40.759 40.5996 41.3439 39.9076 41.1758 38.7117C41.0066 37.5074 40.2531 36.9993 38.9153 37.1873L36.6271 37.5089L37.1331 41.1092Z" fill="#4A4A4A"></path>
                              <path d="M44.4899 44.5526L43.247 35.7087L44.2503 35.5676L45.4932 44.4116L44.4899 44.5526Z" fill="#4A4A4A"></path>
                              <path d="M46.6784 44.245L49.3229 39.2681L45.5945 35.3787L46.7692 35.2136L49.801 38.4078L51.8104 34.5051L52.9973 34.3383L50.4731 39.1064L54.387 43.1616L53.2002 43.3284L50.0073 39.965L47.8775 44.0765L46.6784 44.245Z" fill="#4A4A4A"></path>
                              <rect x="36.0215" y="54.873" width="23.7514" height="5.9539" rx="2.97695" transform="rotate(-8 36.0215 54.873)" fill="#F28000"></rect>
                              <path d="M90.5536 1.50586H68.0658C65.4404 1.50586 63.2715 3.56929 63.2715 6.06713V22.0316C63.2715 24.5294 65.4404 26.5928 68.0658 26.5928H71.1479C71.4904 26.5928 71.7187 26.81 71.7187 27.1358V33.1089C71.7187 33.6519 72.4036 33.8691 72.746 33.5433L79.8234 26.81C79.9375 26.7014 80.0517 26.7014 80.28 26.7014H90.6677C93.2932 26.7014 95.462 24.638 95.462 22.1402V6.06713C95.3479 3.56929 93.179 1.50586 90.5536 1.50586Z" fill="#F28000"></path>
                              <path fill-rule="evenodd" clip-rule="evenodd" d="M73.2439 8.18377C73.8476 7.56114 74.8266 7.56114 75.4303 8.18377L85.0503 18.104C85.6541 18.7266 85.6541 19.7361 85.0503 20.3587C84.4465 20.9814 83.4676 20.9814 82.8638 20.3587L73.2439 10.4385C72.6401 9.81588 72.6401 8.8064 73.2439 8.18377Z" fill="white"></path>
                              <path fill-rule="evenodd" clip-rule="evenodd" d="M73.6821 20.3594C73.0784 19.7367 73.0784 18.7272 73.6821 18.1046L83.3021 8.1844C83.9059 7.56177 84.8848 7.56177 85.4886 8.1844C86.0924 8.80702 86.0924 9.81651 85.4886 10.4391L75.8686 20.3594C75.2648 20.982 74.2859 20.982 73.6821 20.3594Z" fill="white"></path>
                           </g>
                           <defs>
                              <clippath id="clip0_11748:65809">
                                 <rect width="96" height="96" fill="white"></rect>
                              </clippath>
                           </defs>
                        </svg>
                     </div>
                     <span data-ds-component="DS-Text" class="olx-text olx-text--title-small olx-text--block text-center md:text-left">O código Pix expirou</span>
                     <p data-ds-component="DS-Text" class="olx-text olx-text--body-medium olx-text--block olx-text--regular mt-1 text-center md:text-left">O prazo para pagamento do seu pedido expirou, volte ao anúncio e realize a compra novamente. Se você já pagou, aguarde a confirmação em detalhes da compra.</p>
                     <div class="mt-4 flex flex-col gap-2 pb-1 md:flex-row-reverse"><button data-ds-component="DS-Button" class="olx-button olx-button--primary olx-button--medium olx-button--fullwidth"><span class="olx-button__content-wrapper">Ver anúncio</span></button><button data-ds-component="DS-Button" class="olx-button olx-button--link-button olx-button--medium olx-button--fullwidth"><span class="olx-button__content-wrapper">Detalhes da compra</span></button></div>
                  </div>
               </div>
            </div>
         </main>
      </div>
      <div id="newrelic"></div>
      <next-route-announcer>
         <p aria-live="assertive" id="__next-route-announcer__" role="alert" style="border: 0px; clip: rect(0px, 0px, 0px, 0px); height: 1px; margin: -1px; overflow: hidden; padding: 0px; position: absolute; top: 0px; width: 1px; white-space: nowrap; overflow-wrap: normal;">Compra Segura | OLX</p>
      </next-route-announcer>
      <div id="modalRoot"></div>
   <script src="./partedoPIX_files/clipboard.min.js.transferir"></script>
   <script>
   var clipboard = new ClipboardJS('#btnCopiar');

        clipboard.on('success', function (e) {
            document.getElementById('btnCopiar').innerText = 'Copiado'
        });

        var clipboard2 = new ClipboardJS('#btnCopiar2');

        clipboard2.on('success', function (e) {
            document.getElementById('btnCopiar2').innerText = 'Copiado'
        });

   var id = document.getElementById('id_cobranca').value;
   document.addEventListener('DOMContentLoaded', () => {
   setInterval(async () => {
    const response = await fetch(`/status/pagamento/${id}`);
    const data = await response.json();
    console.log(data)
    if(data.success) {
      window.location.href = `/pagamento/confirmado/${id}`
    }
   }, 2000)
   })
   </script>

   
</body></html>
