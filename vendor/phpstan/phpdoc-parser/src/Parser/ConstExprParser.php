<?php declare(strict_types = 1);

namespace PHPStan\PhpDocParser\Parser;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\DoctrineConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayItemNode;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\ParserConfig;
use function str_replace;
use function strtolower;

class ConstExprParser
{

	private ParserConfig $config;

	private bool $parseDoctrineStrings = false;

	public function __construct(
		ParserConfig $config
	)
	{
		$this->config = $config;
	}

	/**
	 * @internal
	 */
	public function toDoctrine(): self
	{
		$self = new self($this->config);
		$self->parseDoctrineStrings = true;
		return $self;
	}

	public function parse(TokenIterator $tokens): ConstExprNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();
		if ($tokens->isCurrentTokenType(Lexer::TOKEN_FLOAT)) {
			$value = $tokens->currentTokenValue();
			$tokens->next();

			return $this->enrichWithAttributes(
				$tokens,
				new ConstExprFloatNode(str_replace('_', '', $value)),
				$startLine,
				$startIndex,
			);
		}

		if ($tokens->isCurrentTokenType(Lexer::TOKEN_INTEGER)) {
			$value = $tokens->currentTokenValue();
			$tokens->next();

			return $this->enrichWithAttributes(
				$tokens,
				new ConstExprIntegerNode(str_replace('_', '', $value)),
				$startLine,
				$startIndex,
			);
		}

		if ($this->parseDoctrineStrings && $tokens->isCurrentTokenType(Lexer::TOKEN_DOCTRINE_ANNOTATION_STRING)) {
			$value = $tokens->currentTokenValue();
			$tokens->next();

			return $this->enrichWithAttributes(
				$tokens,
				new DoctrineConstExprStringNode(DoctrineConstExprStringNode::unescape($value)),
				$startLine,
				$startIndex,
			);
		}

		if ($tokens->isCurrentTokenType(Lexer::TOKEN_SINGLE_QUOTED_STRING, Lexer::TOKEN_DOUBLE_QUOTED_STRING)) {
			if ($this->parseDoctrineStrings) {
				if ($tokens->isCurrentTokenType(Lexer::TOKEN_SINGLE_QUOTED_STRING)) {
					throw new ParserException(
						$tokens->currentTokenValue(),
						$tokens->currentTokenType(),
						$tokens->currentTokenOffset(),
						Lexer::TOKEN_DOUBLE_QUOTED_STRING,
						null,
						$tokens->currentTokenLine(),
					);
				}

				$value = $tokens->currentTokenValue();
				$tokens->next();

				return $this->enrichWithAttributes(
					$tokens,
					$this->parseDoctrineString($value, $tokens),
					$startLine,
					$startIndex,
				);
			}

			$value = StringUnescaper::unescapeString($tokens->currentTokenValue());
			$type = $tokens->currentTokenType();
			$tokens->next();

			return $this->enrichWithAttributes(
				$tokens,
				new ConstExprStringNode(
					$value,
					$type === Lexer::TOKEN_SINGLE_QUOTED_STRING
						? ConstExprStringNode::SINGLE_QUOTED
						: ConstExprStringNode::DOUBLE_QUOTED,
				),
				$startLine,
				$startIndex,
			);

		} elseif ($tokens->isCurrentTokenType(Lexer::TOKEN_IDENTIFIER)) {
			$identifier = $tokens->currentTokenValue();
			$tokens->next();

			switch (strtolower($identifier)) {
				case 'true':
					return $this->enrichWithAttributes(
						$tokens,
						new ConstExprTrueNode(),
						$startLine,
						$startIndex,
					);
				case 'false':
					return $this->enrichWithAttributes(
						$tokens,
						new ConstExprFalseNode(),
						$startLine,
						$startIndex,
					);
				case 'null':
					return $this->enrichWithAttributes(
						$tokens,
						new ConstExprNullNode(),
						$startLine,
						$startIndex,
					);
				case 'array':
					$tokens->consumeTokenType(Lexer::TOKEN_OPEN_PARENTHESES);
					return $this->parseArray($tokens, Lexer::TOKEN_CLOSE_PARENTHESES, $startIndex);
			}

			if ($tokens->tryConsumeTokenType(Lexer::TOKEN_DOUBLE_COLON)) {
				$classConstantName = '';
				$lastType = null;
				while (true) {
					if ($lastType !== Lexer::TOKEN_IDENTIFIER && $tokens->currentTokenType() === Lexer::TOKEN_IDENTIFIER) {
						$classConstantName .= $tokens->currentTokenValue();
						$tokens->consumeTokenType(Lexer::TOKEN_IDENTIFIER);
						$lastType = Lexer::TOKEN_IDENTIFIER;

						continue;
					}

					if ($lastType !== Lexer::TOKEN_WILDCARD && $tokens->tryConsumeTokenType(Lexer::TOKEN_WILDCARD)) {
						$classConstantName .= '*';
						$lastType = Lexer::TOKEN_WILDCARD;

						if ($tokens->getSkippedHorizontalWhiteSpaceIfAny() !== '') {
							break;
						}

						continue;
					}

					if ($lastType === null) {
						// trigger parse error if nothing valid was consumed
						$tokens->consumeTokenType(Lexer::TOKEN_WILDCARD);
					}

					break;
				}

				return $this->enrichWithAttributes(
					$tokens,
					new ConstFetchNode($identifier, $classConstantName),
					$startLine,
					$startIndex,
				);

			}

			return $this->enrichWithAttributes(
				$tokens,
				new ConstFetchNode('', $identifier),
				$startLine,
				$startIndex,
			);

		} elseif ($tokens->tryConsumeTokenType(Lexer::TOKEN_OPEN_SQUARE_BRACKET)) {
			return $this->parseArray($tokens, Lexer::TOKEN_CLOSE_SQUARE_BRACKET, $startIndex);
		}

		throw new ParserException(
			$tokens->currentTokenValue(),
			$tokens->currentTokenType(),
			$tokens->currentTokenOffset(),
			Lexer::TOKEN_IDENTIFIER,
			null,
			$tokens->currentTokenLine(),
		);
	}

	private function parseArray(TokenIterator $tokens, int $endToken, int $startIndex): ConstExprArrayNode
	{
		$items = [];

		$startLine = $tokens->currentTokenLine();

		if (!$tokens->tryConsumeTokenType($endToken)) {
			do {
				$items[] = $this->parseArrayItem($tokens);
			} while ($tokens->tryConsumeTokenType(Lexer::TOKEN_COMMA) && !$tokens->isCurrentTokenType($endToken));
			$tokens->consumeTokenType($endToken);
		}

		return $this->enrichWithAttributes(
			$tokens,
			new ConstExprArrayNode($items),
			$startLine,
			$startIndex,
		);
	}

	/**
	 * This method is supposed to be called with TokenIterator after reading TOKEN_DOUBLE_QUOTED_STRING and shifting
	 * to the next token.
	 */
	public function parseDoctrineString(string $text, TokenIterator $tokens): DoctrineConstExprStringNode
	{
		// Because of how Lexer works, a valid Doctrine string
		// can consist of a sequence of TOKEN_DOUBLE_QUOTED_STRING and TOKEN_DOCTRINE_ANNOTATION_STRING
		while ($tokens->isCurrentTokenType(Lexer::TOKEN_DOUBLE_QUOTED_STRING, Lexer::TOKEN_DOCTRINE_ANNOTATION_STRING)) {
			$text .= $tokens->currentTokenValue();
			$tokens->next();
		}

		return new DoctrineConstExprStringNode(DoctrineConstExprStringNode::unescape($text));
	}

	private function parseArrayItem(TokenIterator $tokens): ConstExprArrayItemNode
	{
		$startLine = $tokens->currentTokenLine();
		$startIndex = $tokens->currentTokenIndex();

		$expr = $this->parse($tokens);

		if ($tokens->tryConsumeTokenType(Lexer::TOKEN_DOUBLE_ARROW)) {
			$key = $expr;
			$value = $this->parse($tokens);

		} else {
			$key = null;
			$value = $expr;
		}

		return $this->enrichWithAttributes(
			$tokens,
			new ConstExprArrayItemNode($key, $value),
			$startLine,
			$startIndex,
		);
	}

	/**
	 * @template T of Ast\ConstExpr\ConstExprNode
	 * @param T $node
	 * @return T
	 */
	private function enrichWithAttributes(TokenIterator $tokens, ConstExprNode $node, int $startLine, int $startIndex): ConstExprNode
	{
		if ($this->config->useLinesAttributes) {
			$node->setAttribute(Attribute::START_LINE, $startLine);
			$node->setAttribute(Attribute::END_LINE, $tokens->currentTokenLine());
		}

		if ($this->config->useIndexAttributes) {
			$node->setAttribute(Attribute::START_INDEX, $startIndex);
			$node->setAttribute(Attribute::END_INDEX, $tokens->endIndexOfLastRelevantToken());
		}

		return $node;
	}

}
