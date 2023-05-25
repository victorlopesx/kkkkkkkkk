<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/app/Pix/pix.config.php';
require __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
require __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';

use \App\Pix\Payload;
use Mpdf\QrCode\QrCode;
use Mpdf\QrCode\Output;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Sao_Paulo');

$mail = new PHPMailer(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    if ($amount >= 50.00 && $amount <= 10000.00) {
        // Gerador de Identificador Único
        function gerarIdentificadorUnico($tamanho = 6) {
            $caracteres = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $quantidadeCaracteres = strlen($caracteres);
            $identificador = '';

            for ($i = 0; $i < $tamanho; $i++) {
                $indiceAleatorio = rand(0, $quantidadeCaracteres - 1);
                $identificador .= $caracteres[$indiceAleatorio];
            }

            return $identificador;
        }

        $identificadorUnico = gerarIdentificadorUnico(6);

        // Atualiza o horário no momento da criação do QR Code
        $dateTime = date('Y-m-d H:i:s');

        // Recupera o valor do formulário e aplica a filtragem de caracteres especiais
        $name = isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '';
        $metatrade = isset($_POST['metatrade']) ? htmlspecialchars($_POST['metatrade']) : '';
        $email = isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '';
        $cpf = isset($_POST['cpf']) ? htmlspecialchars($_POST['cpf']) : '';
        $phone = isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '';

        // Faz a chamada para a API e obtém a taxa de câmbio
        $apiUrl = 'https://economia.awesomeapi.com.br/json/last/USD-BRL';
        $apiResponse = file_get_contents($apiUrl);
        $apiData = json_decode($apiResponse, true);

        // Verifica se a resposta da API foi bem-sucedida
        if ($apiData && isset($apiData['USDBRL']['bid'])) {
            $exchangeRate = floatval($apiData['USDBRL']['bid']);

            // Calcula o valor do mirrorAmount em reais
            $mirrorAmountBRL = ($amount * $exchangeRate) * 1.06;

            // Formata o valor em dólar com 2 casas decimais
            $mirrorAmount = number_format($mirrorAmountBRL, 2, '.', '');

            // Instancia principal payload
            $obPayload = (new Payload)->setPixKey(PIX_KEY)
                ->setDescription('Payment to BRK ' . $dateTime)
                ->setMerchantName(PIX_MERCHANT_NAME)
                ->setMerchantCity(PIX_MERCHANT_CITY)
                ->setAmount($mirrorAmount)
                ->setTxid('USD' . $amount . 'MT' . $metatrade . 'ID' . $identificadorUnico);

            $payloadQrCode = $obPayload->getPayload();

            $obQrCode = new QrCode($payloadQrCode);

            $image = (new Output\Png)->output($obQrCode, 400);

            // Conexão com o banco de dados
            $host = 'mysql.midoricapital.com.br';
            $dbname = 'midoricapital02';
            $username = 'midoricapital02';
            $password = 'XjmNaR75cweKSXH';

            // recebimento codigo payload
            $description = 'Payment to BRK ' . $dateTime;
            $txid = 'USD' . $amount . 'MT' . $metatrade . 'ID' . $identificadorUnico;

            try {
                $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $sql = "INSERT INTO brkHistoryPix (name, metatrade, email, cpf, phone, amount, mirrorAmount, datetime, qrCode, description, transaction_id)
                        VALUES (:name, :metatrade, :email, :cpf, :phone, :amount, :mirrorAmount, :datetime, :qrCode, :description, :transaction_id)";

                // Insere os dados no banco de dados usando parâmetros vinculados
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':metatrade', $metatrade);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':cpf', $cpf);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':amount', $amount);
                $stmt->bindParam(':mirrorAmount', $mirrorAmount);
                $stmt->bindParam(':datetime', $dateTime);
                $stmt->bindParam(':qrCode', $payloadQrCode);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':transaction_id', $txid);
                $stmt->execute();

                // Configurações de envio de e-mail
                $mail->isSMTP();
                $mail->Host = 'smtp.midoricapital.com.br';
                $mail->SMTPAuth = true;
                $mail->Username = 'victor@midoricapital.com.br';
                $mail->Password = 'Laura@0318';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                // Configurações do remetente
                $mail->setFrom('lucas@midoricapital.com.br', 'Lucas');

                // Configurações do destinatário
                $mail->addAddress('victor@midoricapital.com', 'Victor');

                // Conteúdo do e-mail
                $mail->isHTML(true);
                $mail->Subject = 'Novo pagamento recebido';
                $mail->Body = 'Um novo pagamento foi recebido com as seguintes informações:<br><br>'
                    . 'Nome: ' . $name . '<br>'
                    . 'Metatrade: ' . $metatrade . '<br>'
                    . 'E-mail: ' . $email . '<br>'
                    . 'CPF: ' . $cpf . '<br>'
                    . 'Telefone: ' . $phone . '<br>'
                    . 'Valor: ' . $amount . '<br>'
                    . 'Valor em Reais: ' . $mirrorAmount . '<br>'
                    . 'Data e Hora: ' . $dateTime . '<br>'
                    . 'ID da Transação: ' . $txid . '<br>';

                // Anexa a imagem do QR Code ao e-mail
                $mail->addStringAttachment($image, 'qrcode.png', 'base64', 'image/png');

                // Envia o e-mail
                $mail->send();

                // Redirecionar para a página de resultado com o valor do QR Code como parâmetro
                header('Location: payment.php?payload=' . urlencode($payloadQrCode));
                exit;
            } catch (PDOException $e) {
                echo 'Erro na inserção dos dados: ' . $e->getMessage();
            } catch (Exception $e) {
                echo 'Erro ao enviar o email: ' . $e->getMessage();
            }
        } else {
            echo 'Erro ao obter a taxa de câmbio.';
        }
    } else {
        echo 'Valor inválido.';
    }
} else {
    header("Location: https://midoricapital.com.br");
    exit;
}
?>