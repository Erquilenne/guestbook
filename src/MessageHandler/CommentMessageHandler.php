<?php


namespace App\MessageHandler;


use App\Message\CommentMessage;
use App\Repository\CommentRepository;
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

class CommentMessageHandler implements MessageHandlerInterface
{

    private $em;
    private $spamChecker;
    private $commentRepository;
    private $bus;
    private $workflow;
    private $logger;
    private $mailer;
    private $adminEmail;


    public function __construct(
                            EntityManagerInterface $em,
                            SpamChecker $spamChecker,
                            CommentRepository $commentRepository,
                            MessageBusInterface $bus,
                            WorkflowInterface $commentStateMachine,
                            MailerInterface $mailer,
                            string $adminEmail,
                            LoggerInterface $logger=null
                                )
    {

        $this->spamChecker = $spamChecker;
        $this->commentRepository = $commentRepository;
        $this->bus = $bus;
        $this->workflow = $commentStateMachine;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->adminEmail = $adminEmail;
        $this->em = $em;
    }

    public function __invoke(CommentMessage $message)
    {
        $this->logger->info('handler starts');
        $comment = $this->commentRepository->find($message->getId());
        if(!$comment) {
            return;
        }

        if ($this->workflow->can($comment, 'accept')) {
            $score = $this->spamChecker->getSpamScore($comment, $message->getContext());

            $email = (new Email())
                ->from('admin@admin.admin')
                ->to('admin@admin.admin')
                ->subject('Welcome to the Score!')
                ->text(sprintf('here is the score: %s', $score));

            $this->mailer->send($email);

            $transition = 'accept';
            if (2 === $score) {
                $transition = 'reject_spam';
            } elseif (1 === $score) {
                $transition = 'might_be_spam';
            }
            $this->workflow->apply($comment, $transition);
            $this->em->flush();

            $email2 = (new Email())
                ->from('admin@admin.admin')
                ->to('admin@admin.admin')
                ->subject('Welcome to the Space Bar!')
                ->text("Nice to meet you pidor! ❤️");

            $this->mailer->send($email2);

            $this->bus->dispatch($message);
        } elseif ($this->workflow->can($comment, 'publish') || $this->workflow->can($comment, 'publish_ham')) {
            $email = (new NotificationEmail())
                ->subject('New comment posted')
                ->htmlTemplate('emails/comment_notification.html.twig')
                ->from($this->adminEmail)
                ->to($this->adminEmail)
                ->context(['comment' => $comment])
            ;

            $this->mailer->send($email);



        } elseif ($this->logger) {
            $this->logger->debug('Dropping comment message', ['comment' =>
                $comment->getId(), 'state' => $comment->getState()]);
        }
    }
}