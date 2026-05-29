<?php declare(strict_types = 1);

namespace PHPStan\PhpDocParser\Parser;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use LogicException;
use PHPStan\PhpDocParser\Ast;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\ParserConfig;
use function in_array;
use function str_replace;
use function strlen;
use function strpos;
use function substr_compare;

class TypeParser
{

	private ParserConfig $config;

	private ConstExprParser $constExprParser;

	public function __construct(
		ParserConfig $config,
		ConstExprParser $constExprParser
	)
	{
		$this->config = $config;
		$this->constExprParser = $constExprParser;
	}

	/** @phpstan-impure */
	public function parse(TokenIterator $tokens): TypeNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();
		if ($tokens->isCurrentTokenType(Lexer::TOKEN_NULLABLE)) {
			$type = $this->parseNullable($tokens);

		} else {
			$type = $this->parseAtomic($tokens);

			$tokens->pushSavePoint();
			$tokens->skipNewLineTokensAndConsumeComments();

			try {
				$enrichedType = $this->enrichTypeOnUnionOrIntersection($tokens, $type);

			} catch (ParserException) {
				$enrichedType = null;
			}

			if ($enrichedType !== null) {
				$type = $enrichedType;
				$tokens->dropSavePoint();

			} else {
				$tokens->rollback();
				$type = $this->enrichTypeOnUnionOrIntersection($tokens, $type) ?? $type;
			}
		}

		return $this->enrichWithAttributes($tokens, $type, $startLine, $startIndex);
	}

	/** @phpstan-impure */
	private function enrichTypeOnUnionOrIntersection(TokenIterator $tokens, TypeNode $type): ?TypeNode
	{
		if ($tokens->isCurrentTokenType(Lexer::TOKEN_UNION)) {
			return $this->parseUnion($tokens, $type);

		}

		if ($tokens->isCurrentTokenType(Lexer::TOKEN_INTERSECTION)) {
			return $this->parseIntersection($tokens, $type);
		}

		return null;
	}

	/**
	 * @internal
	 * @template T of Ast\Node
	 * @param T $type
	 * @return T
	 */
	public function enrichWithAttributes(TokenIterator $tokens, Node $type, int $startLine, int $startIndex): Node
	{
		if ($this->config->useLinesAttributes) {
			$type->setAttribute(Attribute::START_LINE, $startLine);
			$type->setAttribute(Attribute::END_LINE, $tokens->currentTokenLine());
		}

		$comments = $tokens->flushComments();
		if ($this->config->useCommentsAttributes) {
			$type->setAttribute(Attribute::COMMENTS, $comments);
		}

		if ($this->config->useIndexAttributes) {
			$type->setAttribute(Attribute::START_INDEX, $startIndex);
			$type->setAttribute(Attribute::END_INDEX, $tokens->endIndexOfLastRelevantToken());
		}

		return $type;
	}

	/** @phpstan-impure */
	private function subParse(TokenIterator $tokens): TypeNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		if ($tokens->isCurrentTokenType(Lexer::TOKEN_NULLABLE)) {
			$type = $this->parseNullable($tokens);

		} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_VARIABLE)) {
			$type = $this->parseConditionalForParameter($tokens, $tokens->currentTokenValue());

		} else {
			$type = $this->parseAtomic($tokens);

			if ($tokens->isCurrentTokenValue('is')) {
				$type = $this->parseConditional($tokens, $type);
			} else {
				$tokens->skipNewLineTokensAndConsumeComments();

				if ($tokens->isCurrentTokenType(Lexer::TOKEN_UNION)) {
					$type = $this->subParseUnion($tokens, $type);

				} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_INTERSECTION)) {
					$type = $this->subParseIntersection($tokens, $type);
				}
			}
		}

		return $this->enrichWithAttributes($tokens, $type, $startLine, $startIndex);
	}

	/** @phpstan-impure */
	private function parseAtomic(TokenIterator $tokens): TypeNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		if ($tokens->tryConsumeTokenType(Lexer::TOKEN_OPEN_PARENTHESES)) {
			$tokens->skipNewLineTokensAndConsumeComments();
			$type = $this->subParse($tokens);
			$tokens->skipNewLineTokensAndConsumeComments();

			$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_PARENTHESES);

			if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
				$type = $this->tryParseArrayOrOffsetAccess($tokens, $type);
			}

			return $this->enrichWithAttributes($tokens, $type, $startLine, $startIndex);
		}

		if ($tokens->tryConsumeTokenType(Lexer::TOKEN_THIS_VARIABLE)) {
			$type = $this->enrichWithAttributes($tokens, new ThisTypeNode(), $startLine, $startIndex);

			if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
				$type = $this->tryParseArrayOrOffsetAccess($tokens, $type);
			}

			return $this->enrichWithAttributes($tokens, $type, $startLine, $startIndex);
		}

		$currentTokenValue = $tokens->currentTokenValue();
		$tokens->pushSavePoint(); // because of ConstFetchNode
		if ($tokens->tryConsumeTokenType(Lexer::TOKEN_IDENTIFIER)) {
			$type = $this->enrichWithAttributes($tokens, new IdentifierTypeNode($currentTokenValue), $startLine, $startIndex);

			if (!$tokens->isCurrentTokenType(Lexer::TOKEN_DOUBLE_COLON)) {
				$tokens->dropSavePoint(); // because of ConstFetchNode
				if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET)) {
					$tokens->pushSavePoint();

					$isHtml = $this->isHtml($tokens);
					$tokens->rollback();
					if ($isHtml) {
						return $type;
					}

					$origType = $type;
					$type = $this->tryParseCallable($tokens, $type, true);
					if ($type === $origType) {
						$type = $this->parseGeneric($tokens, $type);

						if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
							$type = $this->tryParseArrayOrOffsetAccess($tokens, $type);
						}
					}
				} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_PARENTHESES)) {
					$type = $this->tryParseCallable($tokens, $type, false);

				} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
					$type = $this->tryParseArrayOrOffsetAccess($tokens, $type);

				} elseif (in_array($type->name, [
					ArrayShapeNode::KIND_ARRAY,
					ArrayShapeNode::KIND_LIST,
					ArrayShapeNode::KIND_NON_EMPTY_ARRAY,
					ArrayShapeNode::KIND_NON_EMPTY_LIST,
					'object',
				], true) && $tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_CURLY_BRACKET) && !$tokens->isPrecededByHorizontalWhitespace()) {
					if ($type->name === 'object') {
						$type = $this->parseObjectShape($tokens);
					} else {
						$type = $this->parseArrayShape($tokens, $type, $type->name);
					}

					if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
						$type = $this->tryParseArrayOrOffsetAccess(
							$tokens,
							$this->enrichWithAttributes($tokens, $type, $startLine, $startIndex),
						);
					}
				}

				return $this->enrichWithAttributes($tokens, $type, $startLine, $startIndex);
			} else {
				$tokens->rollback(); // because of ConstFetchNode
			}
		} else {
			$tokens->dropSavePoint(); // because of ConstFetchNode
		}

		$currentTokenValue = $tokens->currentTokenValue();
		$currentTokenType = $tokens->currentTokenType();
		$currentTokenOffset = $tokens->currentTokenOffset();
		$currentTokenLine = $tokens->currentTokenLine();

		try {
			$constExpr = $this->constExprParser->parse($tokens);
			if ($constExpr instanceof ConstExprArrayNode) {
				throw new ParserException(
					$currentTokenValue,
					$currentTokenType,
					$currentTokenOffset,
					Lexer::TOKEN_IDENTIFIER,
					null,
					$currentTokenLine,
				);
			}

			$type = $this->enrichWithAttributes(
				$tokens,
				new ConstTypeNode($constExpr),
				$startLine,
				$startIndex,
			);
			if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
				$type = $this->tryParseArrayOrOffsetAccess($tokens, $type);
			}

			return $type;
		} catch (LogicException) {
			throw new ParserException(
				$currentTokenValue,
				$currentTokenType,
				$currentTokenOffset,
				Lexer::TOKEN_IDENTIFIER,
				null,
				$currentTokenLine,
			);
		}
	}

	/** @phpstan-impure */
	private function parseUnion(TokenIterator $tokens, TypeNode $type): TypeNode
	{
		$types = [$type];

		while ($tokens->tryConsumeTokenType(Lexer::TOKEN_UNION)) {
			$types[] = $this->parseAtomic($tokens);
			$tokens->pushSavePoint();
			$tokens->skipNewLineTokensAndConsumeComments();
			if (!$tokens->isCurrentTokenType(Lexer::TOKEN_UNION)) {
				$tokens->rollback();
				break;
			}

			$tokens->dropSavePoint();
		}

		return new UnionTypeNode($types);
	}

	/** @phpstan-impure */
	private function subParseUnion(TokenIterator $tokens, TypeNode $type): TypeNode
	{
		$types = [$type];

		while ($tokens->tryConsumeTokenType(Lexer::TOKEN_UNION)) {
			$tokens->skipNewLineTokensAndConsumeComments();
			$types[] = $this->parseAtomic($tokens);
			$tokens->skipNewLineTokensAndConsumeComments();
		}

		return new UnionTypeNode($types);
	}

	/** @phpstan-impure */
	private function parseIntersection(TokenIterator $tokens, TypeNode $type): TypeNode
	{
		$types = [$type];

		while ($tokens->tryConsumeTokenType(Lexer::TOKEN_INTERSECTION)) {
			$types[] = $this->parseAtomic($tokens);
			$tokens->pushSavePoint();
			$tokens->skipNewLineTokensAndConsumeComments();
			if (!$tokens->isCurrentTokenType(Lexer::TOKEN_INTERSECTION)) {
				$tokens->rollback();
				break;
			}

			$tokens->dropSavePoint();
		}

		return new IntersectionTypeNode($types);
	}

	/** @phpstan-impure */
	private function subParseIntersection(TokenIterator $tokens, TypeNode $type): TypeNode
	{
		$types = [$type];

		while ($tokens->tryConsumeTokenType(Lexer::TOKEN_INTERSECTION)) {
			$tokens->skipNewLineTokensAndConsumeComments();
			$types[] = $this->parseAtomic($tokens);
			$tokens->skipNewLineTokensAndConsumeComments();
		}

		return new IntersectionTypeNode($types);
	}

	/** @phpstan-impure */
	private function parseConditional(TokenIterator $tokens, TypeNode $subjectType): TypeNode
	{
		$tokens->consumeTokenType(Lexer::TOKEN_IDENTIFIER);

		$negated = false;
		if ($tokens->isCurrentTokenValue('not')) {
			$negated = true;
			$tokens->consumeTokenType(Lexer::TOKEN_IDENTIFIER);
		}

		$targetType = $this->parse($tokens);

		$tokens->skipNewLineTokensAndConsumeComments();
		$tokens->consumeTokenType(Lexer::TOKEN_NULLABLE);
		$tokens->skipNewLineTokensAndConsumeComments();

		$ifType = $this->parse($tokens);

		$tokens->skipNewLineTokensAndConsumeComments();
		$tokens->consumeTokenType(Lexer::TOKEN_COLON);
		$tokens->skipNewLineTokensAndConsumeComments();

		$elseType = $this->subParse($tokens);

		return new ConditionalTypeNode($subjectType, $targetType, $ifType, $elseType, $negated);
	}

	/** @phpstan-impure */
	private function parseConditionalForParameter(TokenIterator $tokens, string $parameterName): TypeNode
	{
		$tokens->consumeTokenType(Lexer::TOKEN_VARIABLE);
		$tokens->consumeTokenValue(Lexer::TOKEN_IDENTIFIER, 'is');

		$negated = false;
		if ($tokens->isCurrentTokenValue('not')) {
			$negated = true;
			$tokens->consumeTokenType(Lexer::TOKEN_IDENTIFIER);
		}

		$targetType = $this->parse($tokens);

		$tokens->skipNewLineTokensAndConsumeComments();
		$tokens->consumeTokenType(Lexer::TOKEN_NULLABLE);
		$tokens->skipNewLineTokensAndConsumeComments();

		$ifType = $this->parse($tokens);

		$tokens->skipNewLineTokensAndConsumeComments();
		$tokens->consumeTokenType(Lexer::TOKEN_COLON);
		$tokens->skipNewLineTokensAndConsumeComments();

		$elseType = $this->subParse($tokens);

		return new ConditionalTypeForParameterNode($parameterName, $targetType, $ifType, $elseType, $negated);
	}

	/** @phpstan-impure */
	private function parseNullable(TokenIterator $tokens): TypeNode
	{
		$tokens->consumeTokenType(Lexer::TOKEN_NULLABLE);

		$type = $this->parseAtomic($tokens);

		return new NullableTypeNode($type);
	}

	/** @phpstan-impure */
	public function isHtml(TokenIterator $tokens): bool
	{
		$tokens->consumeTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET);

		if (!$tokens->isCurrentTokenType(Lexer::TOKEN_IDENTIFIER)) {
			return false;
		}

		$htmlTagName = $tokens->currentTokenValue();

		$tokens->next();

		if (!$tokens->tryConsumeTokenType(Lexer::TOKEN_CLOSE_ANGLE_BRACKET)) {
			return false;
		}

		$endTag = '</' . $htmlTagName . '>';
		$endTagSearchOffset = - strlen($endTag);

		while (!$tokens->isCurrentTokenType(Lexer::TOKEN_END)) {
			if (
				(
					$tokens->tryConsumeTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET)
					&& str_contains($tokens->currentTokenValue(), '/' . $htmlTagName . '>')
				)
				|| substr_compare($tokens->currentTokenValue(), $endTag, $endTagSearchOffset) === 0
			) {
				return true;
			}

			$tokens->next();
		}

		return false;
	}

	/** @phpstan-impure */
	public function parseGeneric(TokenIterator $tokens, IdentifierTypeNode $baseType): GenericTypeNode
	{
		$tokens->consumeTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET);
		$tokens->skipNewLineTokensAndConsumeComments();

		$startLine = $baseType->getAttribute(Attribute::START_LINE);
		$startIndex = $baseType->getAttribute(Attribute::START_INDEX);
		$genericTypes = [];
		$variances = [];

		$isFirst = true;
		while (
			$isFirst
			|| $tokens->tryConsumeTokenType(Lexer::TOKEN_COMMA)
		) {
			$tokens->skipNewLineTokensAndConsumeComments();

			// trailing comma case
			if (!$isFirst && $tokens->isCurrentTokenType(Lexer::TOKEN_CLOSE_ANGLE_BRACKET)) {
				break;
			}
			$isFirst = false;

			[$genericTypes[], $variances[]] = $this->parseGenericTypeArgument($tokens);
			$tokens->skipNewLineTokensAndConsumeComments();
		}

		$type = new GenericTypeNode($baseType, $genericTypes, $variances);
		if ($startLine !== null && $startIndex !== null) {
			$type = $this->enrichWithAttributes($tokens, $type, $startLine, $startIndex);
		}

		$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_ANGLE_BRACKET);

		return $type;
	}

	/**
     * @phpstan-impure
     * @return array{TypeNode, Ast\Type\GenericTypeNode::VARIANCE_*}
     */
    public function parseGenericTypeArgument(TokenIterator $tokens): array
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();
		if ($tokens->tryConsumeTokenType(Lexer::TOKEN_WILDCARD)) {
			return [
				$this->enrichWithAttributes($tokens, new IdentifierTypeNode('mixed'), $startLine, $startIndex),
				GenericTypeNode::VARIANCE_BIVARIANT,
			];
		}

		if ($tokens->tryConsumeTokenValue('contravariant')) {
			$variance = GenericTypeNode::VARIANCE_CONTRAVARIANT;
		} elseif ($tokens->tryConsumeTokenValue('covariant')) {
			$variance = GenericTypeNode::VARIANCE_COVARIANT;
		} else {
			$variance = GenericTypeNode::VARIANCE_INVARIANT;
		}

		$type = $this->parse($tokens);
		return [$type, $variance];
	}

	/**
	 * @throws ParserException
	 * @param ?callable(TokenIterator): string $parseDescription
	 */
	public function parseTemplateTagValue(
		TokenIterator $tokens,
		?callable $parseDescription = null
	): TemplateTagValueNode
	{
		$name = $tokens->currentTokenValue();
		$tokens->consumeTokenType(Lexer::TOKEN_IDENTIFIER);

		$upperBound = $lowerBound = null;

		if ($tokens->tryConsumeTokenValue('of') || $tokens->tryConsumeTokenValue('as')) {
			$upperBound = $this->parse($tokens);
		}

		if ($tokens->tryConsumeTokenValue('super')) {
			$lowerBound = $this->parse($tokens);
		}

		if ($tokens->tryConsumeTokenValue('=')) {
			$default = $this->parse($tokens);
		} else {
			$default = null;
		}

		if ($parseDescription !== null) {
			$description = $parseDescription($tokens);
		} else {
			$description = '';
		}

		if ($name === '') {
			throw new LogicException('Template tag name cannot be empty.');
		}

		return new TemplateTagValueNode($name, $upperBound, $description, $default, $lowerBound);
	}

	/** @phpstan-impure */
	private function parseCallable(TokenIterator $tokens, IdentifierTypeNode $identifier, bool $hasTemplate): TypeNode
	{
		$templates = $hasTemplate
			? $this->parseCallableTemplates($tokens)
			: [];

		$tokens->consumeTokenType(Lexer::TOKEN_OPEN_PARENTHESES);
		$tokens->skipNewLineTokensAndConsumeComments();

		$parameters = [];
		if (!$tokens->isCurrentTokenType(Lexer::TOKEN_CLOSE_PARENTHESES)) {
			$parameters[] = $this->parseCallableParameter($tokens);
			$tokens->skipNewLineTokensAndConsumeComments();
			while ($tokens->tryConsumeTokenType(Lexer::TOKEN_COMMA)) {
				$tokens->skipNewLineTokensAndConsumeComments();
				if ($tokens->isCurrentTokenType(Lexer::TOKEN_CLOSE_PARENTHESES)) {
					break;
				}
				$parameters[] = $this->parseCallableParameter($tokens);
				$tokens->skipNewLineTokensAndConsumeComments();
			}
		}

		$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_PARENTHESES);
		$tokens->consumeTokenType(Lexer::TOKEN_COLON);

		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();
		$returnType = $this->enrichWithAttributes($tokens, $this->parseCallableReturnType($tokens), $startLine, $startIndex);

		return new CallableTypeNode($identifier, $parameters, $returnType, $templates);
	}

	/**
     * @return TemplateTagValueNode[]
     *
     * @phpstan-impure
     */
    private function parseCallableTemplates(TokenIterator $tokens): array
	{
		$tokens->consumeTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET);

		$templates = [];

		$isFirst = true;
		while ($isFirst || $tokens->tryConsumeTokenType(Lexer::TOKEN_COMMA)) {
			$tokens->skipNewLineTokensAndConsumeComments();

			// trailing comma case
			if (!$isFirst && $tokens->isCurrentTokenType(Lexer::TOKEN_CLOSE_ANGLE_BRACKET)) {
				break;
			}
			$isFirst = false;

			$templates[] = $this->parseCallableTemplateArgument($tokens);
			$tokens->skipNewLineTokensAndConsumeComments();
		}

		$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_ANGLE_BRACKET);

		return $templates;
	}

	private function parseCallableTemplateArgument(TokenIterator $tokens): TemplateTagValueNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		return $this->enrichWithAttributes(
			$tokens,
			$this->parseTemplateTagValue($tokens),
			$startLine,
			$startIndex,
		);
	}

	/** @phpstan-impure */
	private function parseCallableParameter(TokenIterator $tokens): CallableTypeParameterNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();
		$type = $this->parse($tokens);
		$isReference = $tokens->tryConsumeTokenType(Lexer::TOKEN_REFERENCE);
		$isVariadic = $tokens->tryConsumeTokenType(Lexer::TOKEN_VARIADIC);

		if ($tokens->isCurrentTokenType(Lexer::TOKEN_VARIABLE)) {
			$parameterName = $tokens->currentTokenValue();
			$tokens->consumeTokenType(Lexer::TOKEN_VARIABLE);

		} else {
			$parameterName = '';
		}

		$isOptional = $tokens->tryConsumeTokenType(Lexer::TOKEN_EQUAL);
		return $this->enrichWithAttributes(
			$tokens,
			new CallableTypeParameterNode($type, $isReference, $isVariadic, $parameterName, $isOptional),
			$startLine,
			$startIndex,
		);
	}

	/** @phpstan-impure */
	private function parseCallableReturnType(TokenIterator $tokens): TypeNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();
		if ($tokens->isCurrentTokenType(Lexer::TOKEN_NULLABLE)) {
			return $this->parseNullable($tokens);

		} elseif ($tokens->tryConsumeTokenType(Lexer::TOKEN_OPEN_PARENTHESES)) {
			$type = $this->subParse($tokens);
			$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_PARENTHESES);
			if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
				$type = $this->tryParseArrayOrOffsetAccess($tokens, $type);
			}

			return $type;
		} elseif ($tokens->tryConsumeTokenType(Lexer::TOKEN_THIS_VARIABLE)) {
			$type = new ThisTypeNode();
			if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
				$type = $this->tryParseArrayOrOffsetAccess($tokens, $this->enrichWithAttributes(
					$tokens,
					$type,
					$startLine,
					$startIndex,
				));
			}

			return $type;
		} else {
			$currentTokenValue = $tokens->currentTokenValue();
			$tokens->pushSavePoint(); // because of ConstFetchNode
			if ($tokens->tryConsumeTokenType(Lexer::TOKEN_IDENTIFIER)) {
				$type = new IdentifierTypeNode($currentTokenValue);

				if (!$tokens->isCurrentTokenType(Lexer::TOKEN_DOUBLE_COLON)) {
					if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET)) {
						$type = $this->parseGeneric(
							$tokens,
							$this->enrichWithAttributes(
								$tokens,
								$type,
								$startLine,
								$startIndex,
							),
						);
						if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
							$type = $this->tryParseArrayOrOffsetAccess($tokens, $this->enrichWithAttributes(
								$tokens,
								$type,
								$startLine,
								$startIndex,
							));
						}

					} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
						$type = $this->tryParseArrayOrOffsetAccess($tokens, $this->enrichWithAttributes(
							$tokens,
							$type,
							$startLine,
							$startIndex,
						));

					} elseif (in_array($type->name, [
						ArrayShapeNode::KIND_ARRAY,
						ArrayShapeNode::KIND_LIST,
						ArrayShapeNode::KIND_NON_EMPTY_ARRAY,
						ArrayShapeNode::KIND_NON_EMPTY_LIST,
						'object',
					], true) && $tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_CURLY_BRACKET) && !$tokens->isPrecededByHorizontalWhitespace()) {
						if ($type->name === 'object') {
							$type = $this->parseObjectShape($tokens);
						} else {
							$type = $this->parseArrayShape($tokens, $this->enrichWithAttributes(
								$tokens,
								$type,
								$startLine,
								$startIndex,
							), $type->name);
						}

						if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
							$type = $this->tryParseArrayOrOffsetAccess($tokens, $this->enrichWithAttributes(
								$tokens,
								$type,
								$startLine,
								$startIndex,
							));
						}
					}

					return $type;
				} else {
					$tokens->rollback(); // because of ConstFetchNode
				}
			} else {
				$tokens->dropSavePoint(); // because of ConstFetchNode
			}
		}

		$currentTokenValue = $tokens->currentTokenValue();
		$currentTokenType = $tokens->currentTokenType();
		$currentTokenOffset = $tokens->currentTokenOffset();
		$currentTokenLine = $tokens->currentTokenLine();

		try {
			$constExpr = $this->constExprParser->parse($tokens);
			if ($constExpr instanceof ConstExprArrayNode) {
				throw new ParserException(
					$currentTokenValue,
					$currentTokenType,
					$currentTokenOffset,
					Lexer::TOKEN_IDENTIFIER,
					null,
					$currentTokenLine,
				);
			}

			$type = $this->enrichWithAttributes(
				$tokens,
				new ConstTypeNode($constExpr),
				$startLine,
				$startIndex,
			);
			if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
				$type = $this->tryParseArrayOrOffsetAccess($tokens, $type);
			}

			return $type;
		} catch (LogicException) {
			throw new ParserException(
				$currentTokenValue,
				$currentTokenType,
				$currentTokenOffset,
				Lexer::TOKEN_IDENTIFIER,
				null,
				$currentTokenLine,
			);
		}
	}

	/** @phpstan-impure */
	private function tryParseCallable(TokenIterator $tokens, IdentifierTypeNode $identifier, bool $hasTemplate): TypeNode
	{
		try {
			$tokens->pushSavePoint();
			$type = $this->parseCallable($tokens, $identifier, $hasTemplate);
			$tokens->dropSavePoint();

		} catch (ParserException) {
			$tokens->rollback();
			$type = $identifier;
		}

		return $type;
	}

	/** @phpstan-impure */
	private function tryParseArrayOrOffsetAccess(TokenIterator $tokens, TypeNode $type): TypeNode
	{
		$startLine = $type->getAttribute(Attribute::START_LINE);
		$startIndex = $type->getAttribute(Attribute::START_INDEX);
		try {
			while ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
				$tokens->pushSavePoint();

				$canBeOffsetAccessType = !$tokens->isPrecededByHorizontalWhitespace();
				$tokens->consumeTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET);

				if ($canBeOffsetAccessType && !$tokens->isCurrentTokenType(Lexer::TOKEN_CLOSE_SQUARE_BRACKET)) {
					$offset = $this->parse($tokens);
					$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_SQUARE_BRACKET);
					$tokens->dropSavePoint();
					$type = new OffsetAccessTypeNode($type, $offset);

					if ($startLine !== null && $startIndex !== null) {
						$type = $this->enrichWithAttributes(
							$tokens,
							$type,
							$startLine,
							$startIndex,
						);
					}
				} else {
					$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_SQUARE_BRACKET);
					$tokens->dropSavePoint();
					$type = new ArrayTypeNode($type);

					if ($startLine !== null && $startIndex !== null) {
						$type = $this->enrichWithAttributes(
							$tokens,
							$type,
							$startLine,
							$startIndex,
						);
					}
				}
			}

		} catch (ParserException) {
			$tokens->rollback();
		}

		return $type;
	}

	/**
	 * @phpstan-impure
	 * @param Ast\Type\ArrayShapeNode::KIND_* $kind
	 */
	private function parseArrayShape(TokenIterator $tokens, TypeNode $type, string $kind): ArrayShapeNode
	{
		$tokens->consumeTokenType(Lexer::TOKEN_OPEN_CURLY_BRACKET);

		$items = [];
		$sealed = true;
		$unsealedType = null;

		$done = false;

		do {
			$tokens->skipNewLineTokensAndConsumeComments();

			if ($tokens->tryConsumeTokenType(Lexer::TOKEN_CLOSE_CURLY_BRACKET)) {
				return ArrayShapeNode::createSealed($items, $kind);
			}

			if ($tokens->tryConsumeTokenType(Lexer::TOKEN_VARIADIC)) {
				$sealed = false;

				$tokens->skipNewLineTokensAndConsumeComments();
				if ($tokens->isCurrentTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET)) {
					if ($kind === ArrayShapeNode::KIND_ARRAY) {
						$unsealedType = $this->parseArrayShapeUnsealedType($tokens);
					} else {
						$unsealedType = $this->parseListShapeUnsealedType($tokens);
					}
					$tokens->skipNewLineTokensAndConsumeComments();
				}

				$tokens->tryConsumeTokenType(Lexer::TOKEN_COMMA);
				break;
			}

			$items[] = $this->parseArrayShapeItem($tokens);
			$tokens->skipNewLineTokensAndConsumeComments();
			if (!$tokens->tryConsumeTokenType(Lexer::TOKEN_COMMA)) {
				$done = true;
			}
			if ($tokens->currentTokenType() !== Lexer::TOKEN_COMMENT) {
				continue;
			}

			$tokens->next();

		} while (!$done);

		$tokens->skipNewLineTokensAndConsumeComments();
		$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_CURLY_BRACKET);

		if ($sealed) {
			return ArrayShapeNode::createSealed($items, $kind);
		}

		return ArrayShapeNode::createUnsealed($items, $unsealedType, $kind);
	}

	/** @phpstan-impure */
	private function parseArrayShapeItem(TokenIterator $tokens): ArrayShapeItemNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		// parse any comments above the item
		$tokens->skipNewLineTokensAndConsumeComments();

		try {
			$tokens->pushSavePoint();
			$key = $this->parseArrayShapeKey($tokens);
			$optional = $tokens->tryConsumeTokenType(Lexer::TOKEN_NULLABLE);
			$tokens->consumeTokenType(Lexer::TOKEN_COLON);
			$value = $this->parse($tokens);

			$tokens->dropSavePoint();

			return $this->enrichWithAttributes(
				$tokens,
				new ArrayShapeItemNode($key, $optional, $value),
				$startLine,
				$startIndex,
			);
		} catch (ParserException) {
			$tokens->rollback();
			$value = $this->parse($tokens);

			return $this->enrichWithAttributes(
				$tokens,
				new ArrayShapeItemNode(null, false, $value),
				$startLine,
				$startIndex,
			);
		}
	}

	/**
     * @phpstan-impure
     * @return ConstExprIntegerNode|ConstExprStringNode|ConstFetchNode|IdentifierTypeNode
     */
    private function parseArrayShapeKey(TokenIterator $tokens)
	{
		$startIndex = $tokens->currentTokenIndex();
		$startLine = $tokens->currentTokenLine();

		if ($tokens->isCurrentTokenType(Lexer::TOKEN_INTEGER)) {
			$key = new ConstExprIntegerNode(str_replace('_', '', $tokens->currentTokenValue()));
			$tokens->next();

		} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_SINGLE_QUOTED_STRING)) {
			$key = new ConstExprStringNode(StringUnescaper::unescapeString($tokens->currentTokenValue()), ConstExprStringNode::SINGLE_QUOTED);
			$tokens->next();

		} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_DOUBLE_QUOTED_STRING)) {
			$key = new ConstExprStringNode(StringUnescaper::unescapeString($tokens->currentTokenValue()), ConstExprStringNode::DOUBLE_QUOTED);

			$tokens->next();

		} else {
			$identifier = $tokens->currentTokenValue();
			$tokens->consumeTokenType(Lexer::TOKEN_IDENTIFIER);

			if ($tokens->tryConsumeTokenType(Lexer::TOKEN_DOUBLE_COLON)) {
				$classConstantName = $tokens->currentTokenValue();
				$tokens->consumeTokenType(Lexer::TOKEN_IDENTIFIER);

				$key = new ConstFetchNode($identifier, $classConstantName);
			} else {
				$key = new IdentifierTypeNode($identifier);
			}
		}

		return $this->enrichWithAttributes(
			$tokens,
			$key,
			$startLine,
			$startIndex,
		);
	}

	/**
	 * @phpstan-impure
	 */
	private function parseArrayShapeUnsealedType(TokenIterator $tokens): ArrayShapeUnsealedTypeNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		$tokens->consumeTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET);
		$tokens->skipNewLineTokensAndConsumeComments();

		$valueType = $this->parse($tokens);
		$tokens->skipNewLineTokensAndConsumeComments();

		$keyType = null;
		if ($tokens->tryConsumeTokenType(Lexer::TOKEN_COMMA)) {
			$tokens->skipNewLineTokensAndConsumeComments();

			$keyType = $valueType;
			$valueType = $this->parse($tokens);
			$tokens->skipNewLineTokensAndConsumeComments();
		}

		$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_ANGLE_BRACKET);

		return $this->enrichWithAttributes(
			$tokens,
			new ArrayShapeUnsealedTypeNode($valueType, $keyType),
			$startLine,
			$startIndex,
		);
	}

	/**
	 * @phpstan-impure
	 */
	private function parseListShapeUnsealedType(TokenIterator $tokens): ArrayShapeUnsealedTypeNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		$tokens->consumeTokenType(Lexer::TOKEN_OPEN_ANGLE_BRACKET);
		$tokens->skipNewLineTokensAndConsumeComments();

		$valueType = $this->parse($tokens);
		$tokens->skipNewLineTokensAndConsumeComments();

		$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_ANGLE_BRACKET);

		return $this->enrichWithAttributes(
			$tokens,
			new ArrayShapeUnsealedTypeNode($valueType, null),
			$startLine,
			$startIndex,
		);
	}

	/**
	 * @phpstan-impure
	 */
	private function parseObjectShape(TokenIterator $tokens): ObjectShapeNode
	{
		$tokens->consumeTokenType(Lexer::TOKEN_OPEN_CURLY_BRACKET);

		$items = [];

		do {
			$tokens->skipNewLineTokensAndConsumeComments();

			if ($tokens->tryConsumeTokenType(Lexer::TOKEN_CLOSE_CURLY_BRACKET)) {
				return new ObjectShapeNode($items);
			}

			$items[] = $this->parseObjectShapeItem($tokens);

			$tokens->skipNewLineTokensAndConsumeComments();
		} while ($tokens->tryConsumeTokenType(Lexer::TOKEN_COMMA));

		$tokens->skipNewLineTokensAndConsumeComments();
		$tokens->consumeTokenType(Lexer::TOKEN_CLOSE_CURLY_BRACKET);

		return new ObjectShapeNode($items);
	}

	/** @phpstan-impure */
	private function parseObjectShapeItem(TokenIterator $tokens): ObjectShapeItemNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		$tokens->skipNewLineTokensAndConsumeComments();

		$key = $this->parseObjectShapeKey($tokens);
		$optional = $tokens->tryConsumeTokenType(Lexer::TOKEN_NULLABLE);
		$tokens->consumeTokenType(Lexer::TOKEN_COLON);
		$value = $this->parse($tokens);

		return $this->enrichWithAttributes(
			$tokens,
			new ObjectShapeItemNode($key, $optional, $value),
			$startLine,
			$startIndex,
		);
	}

	/**
     * @phpstan-impure
     * @return ConstExprStringNode|IdentifierTypeNode
     */
    private function parseObjectShapeKey(TokenIterator $tokens)
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		if ($tokens->isCurrentTokenType(Lexer::TOKEN_SINGLE_QUOTED_STRING)) {
			$key = new ConstExprStringNode(StringUnescaper::unescapeString($tokens->currentTokenValue()), ConstExprStringNode::SINGLE_QUOTED);
			$tokens->next();

		} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_DOUBLE_QUOTED_STRING)) {
			$key = new ConstExprStringNode(StringUnescaper::unescapeString($tokens->currentTokenValue()), ConstExprStringNode::DOUBLE_QUOTED);
			$tokens->next();

		} else {
			$key = new IdentifierTypeNode($tokens->currentTokenValue());
			$tokens->consumeTokenType(Lexer::TOKEN_IDENTIFIER);
		}

		return $this->enrichWithAttributes($tokens, $key, $startLine, $startIndex);
	}

}
