<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the demo administrator (admin / admin) when it does not exist yet. Demo only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: 'app:demo:setup', description: 'Create the demo administrator (admin / admin)')]
final class DemoSetupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin']) instanceof User) {
            $io->success('Demo administrator already exists (admin / admin).');

            return Command::SUCCESS;
        }

        $user = new User('admin');
        $user->setPassword($this->hasher->hashPassword($user, 'admin'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $io->success('Demo administrator created (admin / admin).');

        return Command::SUCCESS;
    }
}
