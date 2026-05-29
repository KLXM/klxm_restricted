<?php

declare(strict_types=1);

namespace SpomkyLabs\Pki\X509\CertificationPath\PathValidation;

use LogicException;
use SpomkyLabs\Pki\ASN1\Element;
use SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Feature\AlgorithmIdentifierType;
use SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Feature\SignatureAlgorithmIdentifier;
use SpomkyLabs\Pki\CryptoTypes\Asymmetric\PublicKeyInfo;
use SpomkyLabs\Pki\X501\ASN1\Name;
use SpomkyLabs\Pki\X509\Certificate\Certificate;
use SpomkyLabs\Pki\X509\CertificationPath\Policy\PolicyNode;
use SpomkyLabs\Pki\X509\CertificationPath\Policy\PolicyTree;

/**
 * State class for the certification path validation process.
 *
 * @see https://tools.ietf.org/html/rfc5280#section-6.1.1
 * @see https://tools.ietf.org/html/rfc5280#section-6.1.2
 */
final class ValidatorState
{
    /**
     * Length of the certification path (n).
     */
    private ?int $pathLength = null;

    /**
     * Current index in the certification path in the range of 1..n (i).
     */
    private ?int $index = null;

    /**
     * Valid policy tree (valid_policy_tree).
     *
     * A tree of certificate policies with their optional qualifiers. Each of the leaves of the tree represents a valid
     * policy at this stage in the certification path validation. Once the tree is set to NULL, policy processing
     * ceases.
     */
    private ?PolicyTree $validPolicyTree = null;

    /**
     * Permitted subtrees (permitted_subtrees).
     *
     * A set of root names for each name type defining a set of subtrees within which all subject names in subsequent
     * certificates in the certification path must fall.
     */
    private mixed $permittedSubtrees = null;

    /**
     * Excluded subtrees (excluded_subtrees).
     *
     * A set of root names for each name type defining a set of subtrees within which no subject name in subsequent
     * certificates in the certification path may fall.
     */
    private mixed $excludedSubtrees = null;

    /**
     * Explicit policy (explicit_policy).
     *
     * An integer that indicates if a non-NULL valid_policy_tree is required.
     */
    private ?int $explicitPolicy = null;

    /**
     * Inhibit anyPolicy (inhibit_anyPolicy).
     *
     * An integer that indicates whether the anyPolicy policy identifier is considered a match.
     */
    private ?int $inhibitAnyPolicy = null;

    /**
     * Policy mapping (policy_mapping).
     *
     * An integer that indicates if policy mapping is permitted.
     */
    private ?int $policyMapping = null;

    /**
     * Working public key algorithm (working_public_key_algorithm).
     *
     * The digital signature algorithm used to verify the signature of a certificate.
     */
    private SignatureAlgorithmIdentifier|AlgorithmIdentifierType|null $workingPublicKeyAlgorithm = null;

    /**
     * Working public key (working_public_key).
     *
     * The public key used to verify the signature of a certificate.
     */
    private ?PublicKeyInfo $workingPublicKey = null;

    /**
     * Working public key parameters (working_public_key_parameters).
     *
     * Parameters associated with the current public key that may be required to verify a signature.
     */
    private ?Element $workingPublicKeyParameters = null;

    /**
     * Working issuer name (working_issuer_name).
     *
     * The issuer distinguished name expected in the next certificate in the chain.
     */
    private ?Name $workingIssuerName = null;

    /**
     * Maximum certification path length (max_path_length).
     */
    private ?int $maxPathLength = null;

    private function __construct()
    {
    }

    /**
     * Initialize variables according to RFC 5280 6.1.2.
     *
     * @see https://tools.ietf.org/html/rfc5280#section-6.1.2
     *
     * @param Certificate $trustAnchor Trust anchor certificate
     * @param int $n Number of certificates
     * in the certification path
     */
    public static function initialize(PathValidationConfig $config, Certificate $trustAnchor, int $n): self
    {
        $state = new self();
        $state->pathLength = $n;
        $state->index = 1;
        $state->validPolicyTree = PolicyTree::create(PolicyNode::anyPolicyNode());
        $state->permittedSubtrees = null;
        $state->excludedSubtrees = null;
        $state->explicitPolicy = $config->explicitPolicy() ? 0 : $n + 1;
        $state->inhibitAnyPolicy = $config->anyPolicyInhibit() ? 0 : $n + 1;
        $state->policyMapping = $config->policyMappingInhibit() ? 0 : $n + 1;
        $state->workingPublicKeyAlgorithm = $trustAnchor->signatureAlgorithm();
        $tbsCert = $trustAnchor->tbsCertificate();
        $state->workingPublicKey = $tbsCert->subjectPublicKeyInfo();
        $state->workingPublicKeyParameters = self::getAlgorithmParameters(
            $state->workingPublicKey->algorithmIdentifier()
        );
        $state->workingIssuerName = $tbsCert->subject();
        $state->maxPathLength = $config->maxLength();
        return $state;
    }

    /**
     * Get self with current certification path index set.
     */
    public function withIndex(int $index): self
    {
        $state = clone $this;
        $state->index = $index;
        return $state;
    }

    /**
     * Get self with valid_policy_tree.
     */
    public function withValidPolicyTree(PolicyTree $policyTree): self
    {
        $state = clone $this;
        $state->validPolicyTree = $policyTree;
        return $state;
    }

    /**
     * Get self with valid_policy_tree set to null.
     */
    public function withoutValidPolicyTree(): self
    {
        $state = clone $this;
        $state->validPolicyTree = null;
        return $state;
    }

    /**
     * Get self with explicit_policy.
     */
    public function withExplicitPolicy(int $num): self
    {
        $state = clone $this;
        $state->explicitPolicy = $num;
        return $state;
    }

    /**
     * Get self with inhibit_anyPolicy.
     */
    public function withInhibitAnyPolicy(int $num): self
    {
        $state = clone $this;
        $state->inhibitAnyPolicy = $num;
        return $state;
    }

    /**
     * Get self with policy_mapping.
     */
    public function withPolicyMapping(int $num): self
    {
        $state = clone $this;
        $state->policyMapping = $num;
        return $state;
    }

    /**
     * Get self with working_public_key_algorithm.
     */
    public function withWorkingPublicKeyAlgorithm(AlgorithmIdentifierType $algo): self
    {
        $state = clone $this;
        $state->workingPublicKeyAlgorithm = $algo;
        return $state;
    }

    /**
     * Get self with working_public_key.
     */
    public function withWorkingPublicKey(PublicKeyInfo $pubkeyInfo): self
    {
        $state = clone $this;
        $state->workingPublicKey = $pubkeyInfo;
        return $state;
    }

    /**
     * Get self with working_public_key_parameters.
     */
    public function withWorkingPublicKeyParameters(?Element $params = null): self
    {
        $state = clone $this;
        $state->workingPublicKeyParameters = $params;
        return $state;
    }

    /**
     * Get self with working_issuer_name.
     */
    public function withWorkingIssuerName(Name $issuer): self
    {
        $state = clone $this;
        $state->workingIssuerName = $issuer;
        return $state;
    }

    /**
     * Get self with max_path_length.
     */
    public function withMaxPathLength(int $length): self
    {
        $state = clone $this;
        $state->maxPathLength = $length;
        return $state;
    }

    /**
     * Get the certification path length (n).
     */
    public function pathLength(): int
    {
        return $this->pathLength;
    }

    /**
     * Get the current index in certification path in the range of 1..n.
     */
    public function index(): int
    {
        return $this->index;
    }

    /**
     * Check whether valid_policy_tree is present.
     */
    public function hasValidPolicyTree(): bool
    {
        return isset($this->validPolicyTree);
    }

    public function validPolicyTree(): PolicyTree
    {
        if (! $this->hasValidPolicyTree()) {
            throw new LogicException('valid_policy_tree not set.');
        }
        return $this->validPolicyTree;
    }

    public function permittedSubtrees(): mixed
    {
        return $this->permittedSubtrees;
    }

    public function excludedSubtrees(): mixed
    {
        return $this->excludedSubtrees;
    }

    public function explicitPolicy(): int
    {
        return $this->explicitPolicy;
    }

    public function inhibitAnyPolicy(): int
    {
        return $this->inhibitAnyPolicy;
    }

    public function policyMapping(): int
    {
        return $this->policyMapping;
    }

    public function workingPublicKeyAlgorithm(): AlgorithmIdentifierType
    {
        return $this->workingPublicKeyAlgorithm;
    }

    public function workingPublicKey(): PublicKeyInfo
    {
        return $this->workingPublicKey;
    }

    public function workingPublicKeyParameters(): ?Element
    {
        return $this->workingPublicKeyParameters;
    }

    public function workingIssuerName(): Name
    {
        return $this->workingIssuerName;
    }

    /**
     * Get maximum certification path length.
     */
    public function maxPathLength(): int
    {
        return $this->maxPathLength;
    }

    /**
     * Check whether processing the final certificate of the certification path.
     */
    public function isFinal(): bool
    {
        return $this->index === $this->pathLength;
    }

    /**
     * Get the path validation result.
     *
     * @param Certificate[] $certificates Certificates in a certification path
     */
    public function getResult(array $certificates): PathValidationResult
    {
        return PathValidationResult::create(
            $certificates,
            $this->validPolicyTree,
            $this->workingPublicKey,
            $this->workingPublicKeyAlgorithm,
            $this->workingPublicKeyParameters
        );
    }

    /**
     * Get ASN.1 parameters from algorithm identifier.
     *
     * @return null|Element ASN.1 element or null if parameters are omitted
     */
    public static function getAlgorithmParameters(AlgorithmIdentifierType $algo): ?Element
    {
        $seq = $algo->toASN1();
        return $seq->has(1) ? $seq->at(1)
            ->asElement() : null;
    }
}
