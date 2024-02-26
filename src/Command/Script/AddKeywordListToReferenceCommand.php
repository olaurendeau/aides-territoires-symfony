<?php

namespace App\Command\Script;

use App\Entity\Keyword\KeywordSynonymlist;
use App\Entity\Reference\KeywordReference;
use App\Service\Reference\ReferenceService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'at:script:keyword_synonyms_to_reference', description: 'Import des mots-clés référents')]
class AddKeywordListToReferenceCommand extends Command
{

    protected InputInterface $input;
    protected OutputInterface $output;
    protected string $commandTextStart = '<Import des anciens mots-clés';
    protected string $commandTextEnd = '>Import des anciens mots-clés';

    

    public function __construct(
        protected ManagerRegistry $managerRegistry,
        protected ReferenceService $referenceService
    )
    {
        ini_set('max_execution_time', 60*60);
        ini_set('memory_limit', '1G');
        parent::__construct();
    }

    protected function configure() : void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $io = new SymfonyStyle($input, $output);
        $io->title($this->commandTextStart);

        try  {
            // import des keywords
            $this->importKeyword($input, $output);
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->title($this->commandTextEnd);
        return Command::SUCCESS;
    }    

    protected function importKeyword($input, $output): void
    {
        $keywordSynonyms = $this->managerRegistry->getRepository(KeywordSynonymlist::class)->findAll();

        /** @var KeywordSynonymlist $keywordSynonym */
        foreach ($keywordSynonyms as $keywordSynonym) {
            $synonyms = explode(',', $keywordSynonym->getKeywordsList());
            // on regarde si déjà présent
            $keywodReference = $this->managerRegistry->getRepository(KeywordReference::class)->findOneBy(['name' => $keywordSynonym->getName()]);
            // si on a pas trouvé on regarde avec les synonymes
            $keywodReference = $keywodReference ?? $this->managerRegistry->getRepository(KeywordReference::class)->findCustom(['names' => $synonyms]);
            // déjà présent, on va voir pour lui ajouter les synonymes
            if ($keywodReference instanceof KeywordReference) {
                
                foreach ($synonyms as $synonym) {
                    $synonym = trim($synonym);
                    if (!$this->referenceService->keywordHasSynonym($keywodReference, $synonym)) {
                        $newKeyword = new KeywordReference();
                        $newKeyword->setName($synonym);
                        $newKeyword->setIntention(false);
                        $keywodReference->addKeywordReference($newKeyword);
                    }
                }
            } else { // c'est un nouveau
                $keywodReference = new KeywordReference();
                $keywodReference->setName(trim($keywordSynonym->getName()));
                $keywodReference->setIntention(false);
                foreach ($synonyms as $synonym) {
                    $synonym = trim($synonym);
                    $newKeyword = new KeywordReference();
                    $newKeyword->setName($synonym);
                    $newKeyword->setIntention(false);
                    $keywodReference->addKeywordReference($newKeyword);
                }
            }

            $this->managerRegistry->getManager()->persist($keywodReference);
            $this->managerRegistry->getManager()->flush();
        }
    }

}