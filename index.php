<?php
require_once 'vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

$accountName   = getenv('AZURE_STORAGE_ACCOUNT') ?: '';
$containerName = 'photos';
$maxBytes = 8 * 1024 * 1024;
$allowedExt = ['jpg','jpeg','png','gif','webp'];

function getManagedIdentityToken(string $resource = 'https://storage.azure.com/'): string {
    $endpoint = getenv('IDENTITY_ENDPOINT');
    $secret   = getenv('IDENTITY_HEADER');
    $clientId = getenv('AZURE_CLIENT_ID');

    if ($endpoint && $secret) {
        $query = ['resource' => $resource, 'api-version' => '2019-08-01'];
        if ($clientId) $query['client_id'] = $clientId;

        $ch = curl_init($endpoint . '?' . http_build_query($query));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-IDENTITY-HEADER: ' . $secret]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        if ($resp === false) throw new \RuntimeException('Échec token App Service : ' . curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) throw new \RuntimeException('Échec token App Service (' . $code . ') : ' . $resp);
        $json = json_decode($resp, true);
        if (!isset($json['access_token'])) throw new \RuntimeException('Réponse token invalide côté App Service');
        return $json['access_token'];
    }

    $query = ['resource' => $resource, 'api-version' => '2018-02-01'];
    if ($clientId) $query['client_id'] = $clientId;

    $url = 'http://169.254.169.254/metadata/identity/oauth2/token?' . http_build_query($query);
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Metadata: true']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if ($resp === false) throw new \RuntimeException('Échec token IMDS : ' . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) throw new \RuntimeException('Échec token IMDS (' . $code . ') : ' . $resp);
    $json = json_decode($resp, true);
    if (!isset($json['access_token'])) throw new \RuntimeException('Réponse token invalide côté IMDS');
    return $json['access_token'];
}

$uploadMessage = '';
$blobClient    = null;

try {
    if (empty($accountName)) throw new \RuntimeException("Variable d'environnement AZURE_STORAGE_ACCOUNT non définie.");
    $token = getManagedIdentityToken('https://storage.azure.com/');
    $connectionString = "DefaultEndpointsProtocol=https;AccountName={$accountName};EndpointSuffix=core.windows.net";
    $blobClient = BlobRestProxy::createBlobServiceWithTokenCredential($token, $connectionString);
} catch (\Throwable $e) {
    $uploadMessage = "<p style='color: red;'>Erreur d'initialisation : " . htmlspecialchars($e->getMessage()) . "</p>";
}

if ($blobClient && isset($_GET['download'])) {
    $blobName = basename($_GET['download']);
    try {
        $blob    = $blobClient->getBlob($containerName, $blobName);
        $props   = $blob->getProperties();
        $stream  = $blob->getContentStream();

        header('Content-Type: ' . ($props->getContentType() ?: 'application/octet-stream'));
        if ($props->getContentLength() !== null) header('Content-Length: ' . $props->getContentLength());

        fpassthru($stream);
    } catch (\Throwable $e) {
        http_response_code(404);
        echo "Introuvable : " . htmlspecialchars($e->getMessage());
    }
    exit;
}

if ($blobClient && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    $err = $_FILES['fileToUpload']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => "Fichier trop volumineux.",
            UPLOAD_ERR_FORM_SIZE  => "Fichier trop volumineux.",
            UPLOAD_ERR_PARTIAL    => "Téléversement partiel, réessayez.",
            UPLOAD_ERR_NO_FILE    => "Aucun fichier reçu.",
        ];
        $uploadMessage = "<p style='color: red;'>" . ($map[$err] ?? "Erreur inconnue de téléversement.") . "</p>";
    } else {
        $fileTmpPath = $_FILES['fileToUpload']['tmp_name'] ?? '';
        $origName    = $_FILES['fileToUpload']['name'] ?? 'fichier';
        $mimeType    = $_FILES['fileToUpload']['type'] ?: 'application/octet-stream';
        $sizeBytes   = (int)($_FILES['fileToUpload']['size'] ?? 0);

        if (!is_uploaded_file($fileTmpPath) || $sizeBytes <= 0) {
            $uploadMessage = "<p style='color: red;'>Le fichier n'est pas un téléversement valide.</p>";
        } else {
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                $uploadMessage = "<p style='color: red;'>Extension non autorisée.</p>";
            } else {
                $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
                $options = new CreateBlockBlobOptions();
                $options->setContentType($mimeType);
                $stream = @fopen($fileTmpPath, 'rb');
                if ($stream === false) {
                    $uploadMessage = "<p style='color: red;'>Impossible d'ouvrir le fichier temporaire.</p>";
                } else {
                    try {
                        $blobClient->createBlockBlob($containerName, $safeName, $stream, $options);
                        $uploadMessage = "<p style='color: green;'>Image téléversée avec succès.</p>";
                    } catch (\Throwable $e) {
                        $uploadMessage = "<p style='color: red;'>Erreur lors du téléversement : " . htmlspecialchars($e->getMessage()) . "</p>";
                    } finally {
                        if (is_resource($stream)) @fclose($stream);
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>GlobalShare - Galerie</title>
  <style>
    body { font-family: Arial, sans-serif; background-color: #f4f4f4; color: #333; margin: 20px; }
    h1, h2 { color: #0078D4; }
    .container { max-width: 900px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 20px; }
    .gallery img { width: 100%; height: auto; border-radius: 4px; border: 1px solid #ddd; }
    form { margin-bottom: 30px; }
  </style>
</head>
<body>
  <div class="container">
    <h1>GlobalShare - Votre Galerie Photo</h1>
    <form action="index.php" method="post" enctype="multipart/form-data">
      <h2>Téléverser une nouvelle image</h2>
      <input type="file" name="fileToUpload" id="fileToUpload" accept=".jpg,.jpeg,.png,.gif,.webp" required>
      <input type="submit" value="Téléverser l'image" name="submit">
    </form>
    <?php echo $uploadMessage; ?>
    <h2>Photos Téléversées</h2>
    <div class="gallery">
      <?php
      if ($blobClient) {
        try {
          $result = $blobClient->listBlobs($containerName, new ListBlobsOptions());
          $blobs  = $result->getBlobs();
          if ($blobs) {
            foreach ($blobs as $blob) {
              $name = $blob->getName();
              $url  = 'index.php?download=' . rawurlencode($name);
              echo '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($name) . '">';
            }
          } else {
            echo "<p>Aucune photo téléversée pour le moment.</p>";
          }
        } catch (ServiceException $e) {
          echo "<p style='color: red;'>Erreur lors de la récupération des images : " . htmlspecialchars($e->getMessage()) . "</p>";
        }
      }
      ?>
    </div>
  </div>
</body>
</html>
