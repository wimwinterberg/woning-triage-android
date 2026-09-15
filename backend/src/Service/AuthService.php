<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\IdGenerator;
use App\Entity\AccessToken;
use App\Entity\ActivationCode;
use App\Entity\User;
use App\Exception\NotFoundException;
use App\Exception\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;

final class AuthService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{user: User, activation_code: string}
     */
    public function createUser(string $label): array
    {
        $user = new User(IdGenerator::prefixed('user'), $label);
        $plain = strtoupper(bin2hex(random_bytes(4))).'-'.strtoupper(bin2hex(random_bytes(4)));
        $code = new ActivationCode(
            IdGenerator::prefixed('act'),
            $user,
            hash('sha256', $plain),
            new \DateTimeImmutable('+14 days', new \DateTimeZone('UTC')),
        );
        $this->entityManager->persist($user);
        $this->entityManager->persist($code);
        $this->entityManager->flush();

        return ['user' => $user, 'activation_code' => $plain];
    }

    /**
     * @return array{access_token: string, user_id: string}
     */
    public function activate(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new ValidationFailedException('Activatcode ontbreekt.');
        }
        $record = $this->entityManager->getRepository(ActivationCode::class)->findOneBy([
            'codeHash' => hash('sha256', $code),
        ]);
        if (!$record instanceof ActivationCode || !$record->isUsable()) {
            throw new NotFoundException('Activatcode is ongeldig of al gebruikt.');
        }
        $record->markUsed();
        $plainToken = 'wt_'.bin2hex(random_bytes(24));
        $token = new AccessToken(IdGenerator::prefixed('tok'), $record->getUser(), hash('sha256', $plainToken));
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return [
            'access_token' => $plainToken,
            'user_id' => $record->getUser()->getId(),
        ];
    }

    /**
     * @return array{access_token: string, user_id: string}
     */
    public function issueToken(User $user): array
    {
        $plainToken = 'wt_'.bin2hex(random_bytes(24));
        $token = new AccessToken(IdGenerator::prefixed('tok'), $user, hash('sha256', $plainToken));
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return ['access_token' => $plainToken, 'user_id' => $user->getId()];
    }
}
