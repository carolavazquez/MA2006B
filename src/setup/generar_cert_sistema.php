<?php
$config = [
    'digest_alg'       => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA
];

$par_claves = openssl_pkey_new($config);

$dn = [
    'commonName'             => 'Sistema Casa Monarca',
    'organizationName'       => 'Casa Monarca',
    'organizationalUnitName' => 'Sistema',
    'countryName'            => 'MX',
    'emailAddress'           => 'no-reply@casamonarca.org'
];

$csr = openssl_csr_new($dn, $par_claves);
$certificado = openssl_csr_sign($csr, null, $par_claves, 3650);

openssl_x509_export($certificado, $crt_string);
openssl_pkey_export($par_claves, $key_string);

$dir = '/var/certificados/sistema';
if (!is_dir($dir)) mkdir($dir, 0700, true);

file_put_contents("$dir/sistema.crt", $crt_string);
file_put_contents("$dir/sistema.key", $key_string);
chmod("$dir/sistema.key", 0600);

echo "Certificado del sistema generado:\n";
echo "  - $dir/sistema.crt\n";
echo "  - $dir/sistema.key\n";
echo "Vigencia: 10 años\n";