<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Order\CoverRuleDiscounts\CoverRuleDiscountsHandler;
use App\Domain\Identity\UserRepository;
use App\Infrastructure\Security\SecurityUser;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[AsCommand(name: 'app:orders:cover-rule-discounts', description: 'Shares the rule discounts recorded before they kept their units between the lines they cover, in the workspace of the given user')]
final readonly class CoverRuleDiscountsCommand
{
    public function __construct(
        private UserRepository $users,
        private TokenStorageInterface $tokens,
        private CoverRuleDiscountsHandler $handler,
    ) {
    }

    public function __invoke(OutputInterface $output, #[Argument('Email of a member of the workspace')] string $email): int
    {
        $user = $this->users->findByEmail($email);
        if (null === $user) {
            $output->writeln(\sprintf('No user with the email %s.', $email));

            return Command::FAILURE;
        }

        $member = SecurityUser::fromUser($user);
        $this->tokens->setToken(new UsernamePasswordToken($member, 'main', $member->getRoles()));
        $output->writeln(\sprintf('%d orders now share their rule discounts between the lines they cover.', ($this->handler)()));

        return Command::SUCCESS;
    }
}
