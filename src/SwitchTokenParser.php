<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

use Twig\Error\SyntaxError;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Class SwitchTokenParser that parses {% switch %} tags. Based on Craft CMS.
 *
 * @see https://github.com/craftcms/cms.
 */
final class SwitchTokenParser extends AbstractTokenParser {

  /**
   * {@inheritdoc}
   */
  public function getTag(): string {
    return 'switch';
  }

  /**
   * {@inheritdoc}
   */
  public function parse(Token $token): SwitchNode {
    $lineno = $token->getLine();
    $parser = $this->parser;
    $stream = $parser->getStream();

    $nodes = [
      'value' => $parser->getExpressionParser()->parseExpression(),
    ];

    $stream->expect(Token::BLOCK_END_TYPE);

    // Trim whitespace between the {% switch %} and first {% case %} tag.
    while ($stream->getCurrent()->getType() === Token::TEXT_TYPE && trim($stream->getCurrent()->getValue()) === '') {
      $stream->next();
    }

    $stream->expect(Token::BLOCK_START_TYPE);

    $expressionParser = $parser->getExpressionParser();
    $cases = [];
    $end = FALSE;

    while (!$end) {
      $next = $stream->next();

      switch ($next->getValue()) {
        case 'case':
          $values = [];
          while (TRUE) {
            $values[] = $expressionParser->parsePrimaryExpression();
            // Multiple allowed values?
            if ($stream->test(Token::OPERATOR_TYPE, 'or')) {
              $stream->next();
            }
            else {
              break;
            }
          }
          $stream->expect(Token::BLOCK_END_TYPE);
          $body = $parser->subparse([$this, 'decideIfFork']);
          $cases[] = new Node([
            'values' => new Node($values),
            'body' => $body,
          ]);
          break;

        case 'default':
          $stream->expect(Token::BLOCK_END_TYPE);
          $nodes['default'] = $parser->subparse([$this, 'decideIfEnd']);
          break;

        case 'endswitch':
          $end = TRUE;
          break;

        default:
		throw new SyntaxError(sprintf('Unexpected tag "%s". Twig was looking for "case", "default", or "endswitch" to close the "switch" block started on line %d.', $next->getValue(), $lineno), $lineno);
      }
    }

    $nodes['cases'] = new Node($cases);

    $stream->expect(Token::BLOCK_END_TYPE);

    return new SwitchNode($nodes, [], $lineno, $this->getTag());
  }

  /**
   * Decide IF Fork.
   *
   * @param \Twig\Token $token
   *   The token to parse.
   *
   * @return bool
   *   Returns if one of the tokens is part of this switch statement.
   */
  public function decideIfFork(Token $token): bool {
    return $token->test(['case', 'default', 'endswitch']);
  }

  /**
   * Decides if end of switch statement.
   *
   * @param \Twig\Token $token
   *   The token to parse.
   *
   * @return bool
   *   Returns if we are at the end of the switch statement.
   */
  public function decideIfEnd(Token $token): bool {
    return $token->test(['endswitch']);
  }

}
