<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Identity\AccessTokenIssuer;
use App\Identity\Dto\Input\CreateUserInput;
use App\Identity\IssuedToken;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'almanac:user:create', description: 'Create a user, or rotate the tokens of an existing one, and print a new access token once')]
final readonly class CreateUserCommand
{
    public function __construct(
        private AccessTokenIssuer $issuer,
    ) {
    }

    public function __invoke(#[MapInput] CreateUserInput $input, SymfonyStyle $io): int
    {
        self::refuseToDiscardTheToken($io);
        $issued = $this->issuer->issueFor($input->toEmail(), $input->toTtl());
        self::print($issued, $io);

        return Command::SUCCESS;
    }

    private static function refuseToDiscardTheToken(SymfonyStyle $io): void
    {
        if ($io->isSilent()) {
            throw new InvalidOptionException('--silent would discard the only copy of the new token; use --quiet to print the token alone.');
        }
    }

    private static function print(IssuedToken $issued, SymfonyStyle $io): void
    {
        $io->success('Access token issued. It is shown only this once, on the last line, and every earlier token of this user is revoked.');
        $io->definitionList(
            ['User id' => $issued->token->user->id->toRfc4122()],
            ['Expires at' => $issued->token->expiresAt->format(\DateTimeInterface::ATOM)],
        );
        $io->writeln($issued->plainToken, OutputInterface::VERBOSITY_QUIET);
    }
}
