<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Ai;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Read model for the FastMagento AI settings (fastmagento/ai/*). The API key is stored
 * encrypted (obscure field + Encrypted backend model), so getApiKey() decrypts it before use.
 */
class AiConfig
{
    private const XML_API_KEY = 'fastmagento/ai/claude_api_key';
    private const XML_MODEL = 'fastmagento/ai/claude_model';
    private const XML_MAX_TERMS = 'fastmagento/ai/max_terms';
    private const DEFAULT_MODEL = 'claude-opus-4-8';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function getApiKey(): string
    {
        $stored = (string) $this->scopeConfig->getValue(self::XML_API_KEY, ScopeInterface::SCOPE_STORE);
        if ($stored === '') {
            return '';
        }
        try {
            return trim((string) $this->encryptor->decrypt($stored));
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getModel(): string
    {
        $model = trim((string) $this->scopeConfig->getValue(self::XML_MODEL, ScopeInterface::SCOPE_STORE));
        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    /**
     * Upper bound on catalog vocabulary terms sent to the model (keeps the prompt bounded on
     * large catalogs). Defaults to 1200.
     */
    public function getMaxTerms(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_MAX_TERMS, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : 1200;
    }
}
