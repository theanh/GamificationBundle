<?php

/**
 * GamificationEventService service
 *
 * @author Damian Piela <damian.piela@tmsolution.pl>
 * @author Lukasz Sobieraj <lukasz.sobieraj@tmsolution.pl>
 * @author Jacek Lozinski <jacek.lozinski@tmsolution.pl>
 */

namespace TMSolution\GamificationBundle\Service;

use TMSolution\GamificationBundle\Entity\GamificationEventlog;
use TMSolution\GamificationBundle\Entity\GamificationEventcounter;
use TMSolution\GamificationBundle\Entity\Gamertrophy;
use Hoa\Ruler\Ruler;
use Hoa\Ruler\Context;
use Symfony\Component\HttpFoundation\Response;
use Gos\Bundle\WebSocketBundle\Periodic\PeriodicInterface;

class GamificationEventsService implements PeriodicInterface
{

    protected $container;
    protected $model;
    protected $gamerInstanceModel;
    protected $eventModel;
    protected $eventLogModel;
    protected $eventCounterModel;
    protected $gamerTrophyModel;
    protected $ruleModel;
    protected $contextModel;
    protected $trophyModel;

    public function __construct($container)
    {
        $this->container = $container;
        $this->model = $this->container->get('model_factory');
        $this->gamerInstanceModel = $this->model->getModel('TMSolution\GamificationBundle\Entity\Gamerinstance');
        $this->eventModel = $this->model->getModel('TMSolution\GamificationBundle\Entity\GamificationEvent');
        $this->eventLogModel = $this->model->getModel('TMSolution\GamificationBundle\Entity\GamificationEventlog');
        $this->eventCounterModel = $this->model->getModel('TMSolution\GamificationBundle\Entity\GamificationEventcounter');
        $this->gamerTrophyModel = $this->model->getModel('TMSolution\GamificationBundle\Entity\Gamertrophy');
        $this->ruleModel = $this->model->getModel('TMSolution\GamificationBundle\Entity\Rule');
        $this->contextModel = $this->model->getModel('TMSolution\GamificationBundle\Entity\Context');
        $this->trophyModel = $this->model->getModel('TMSolution\GamificationBundle\Entity\Trophy');
    }

    public function tick()
    {
        echo "Executed once every 5 seconds" . PHP_EOL;
    }

    public function getTimeout()
    {
        return 3;
    }

    /**
     * The method registers events in the database. 
     * In case the person for whom the event to be registered exists, the function 
     * creates a new entry in the db and increments the counter.
     * If the object exists, the method increments a counter.
     * 
     * @param integer $eventCategoryId
     * @param integer $gamerIdentity
     * @param integer $gamerTypeId
     */
    public function register($eventCategoryId, $gamerIdentity, $gamerTypeId)
    {
        $gamerInstance = $this->gamerInstanceModel->getInstance($gamerIdentity, $gamerTypeId);
        if ($gamerInstance) {
            $event = $this->eventModel->findOneById($eventCategoryId);
            $eventLogEntity = new GamificationEventlog();
            $eventLogEntity->setGamificationEvent($event)
                    ->setGamerInstance($gamerInstance)
                    ->setDate(new \DateTime('NOW'));
            $this->eventLogModel->create($eventLogEntity, true);
            try {
                $eventCounterEntity = $this->eventCounterModel->findOneBy(['gamerinstance' => $gamerInstance, 'event' => $event]);
                $eventCounterEntity->setCounter($eventCounterEntity->getCounter() + 1);
                $this->eventCounterModel->update($eventCounterEntity, true);
            } catch (\Exception $e) {
                $eventCounterEntity = new GamificationEventcounter();
                $eventCounterEntity->setGamificationEvent($event);
                $eventCounterEntity->setGamerInstance($gamerInstance);
                $eventCounterEntity->setCounter($eventCounterEntity->getCounter() + 1);
                $this->eventCounterModel->update($eventCounterEntity, true);
            }
        }
    }

    /**
     * Function, upon ensuring that input data is correct, creates a new entity which represents
     * a single entry in the gamertrophy database table.
     * Additionally, it returns the object it has created, allowing for a more strict operation control.
     * 
     * @param Gamerinstance $gamerInstance
     * @param Trophy $trophy
     * @return Gamertrophy $gamerTrophy
     */
    public function addGamerTrophy(\TMSolution\GamificationBundle\Entity\Gamerinstance $gamerInstance, \TMSolution\GamificationBundle\Entity\Trophy $trophy)
    {
        if ($gamerInstance && $trophy) {
            $gamerTrophy = $this->gamerTrophyModel->getEntity();
            $gamerTrophy->setDate(new \DateTime('NOW'))
                    ->setGamerinstance($gamerInstance)
                    ->setTrophy($trophy);
            $this->gamerTrophyModel->create($gamerTrophy, true);
            return $gamerTrophy;
        }
    }

    /**
     * Looks for user's trophies in the database, if the $trophyCategory paramenter is given, 
     * the returned results are of the given type.
     * Otherwise, it returns all the trophies of that particular user.
     * The result is an array, which is the result obtained from the database.
     * 
     * @param Gamerinstance $gamerInstance
     * @param TrophyCategory $troptrophyCategory
     * @return array $result
     */
    public function getGamerTrophies(\TMSolution\GamificationBundle\Entity\Gamerinstance $gamerInstance, \TMSolution\GamificationBundle\Entity\TrophyCategory $trophyCategory = null)
    {
        if ($trophyCategory != null) {
            $result = $this->gamerTrophyModel->findBy(['gamer' => $gamerInstance, 'trophy' => $trophyCategory]);
        } else {
            $result = $this->gamerTrophyModel->findBy(['gamer' => $gamerInstance]);
        }
        return $result;
    }

    /**
     * Checks rule and decides if a trophy can be given.
     * 
     * @param Gamerinstance $gamerInstance
     * @param Trophy $trophy
     * @return Response
     */
    public function checkRule(\TMSolution\GamificationBundle\Entity\Gamerinstance $gamerInstance, \TMSolution\GamificationBundle\Entity\Trophy $trophy)
    {

        $gamerRule = $this->ruleModel->getRepository()->findOneBy(['trophy' => $trophy]);
        $gamerContext = $this->contextModel->getRepository()->findOneBy(['id' => $gamerRule->getContext()->getId()]);
        $trophyCount = $this->countTrophies($gamerInstance, $trophy);
        $cyclicCount = $this->countCyclicTrophies($gamerInstance);
        $assertion = $this->assertion($gamerContext->getName(), $gamerRule->getOperator(), $gamerRule->getValue(), $cyclicCount);
        if ($trophy->getTrophyType()->getId() == 1/* Jednorazowa */) {
            if (($assertion == true) && ($trophyCount == 0)) {
                $gamerTrophy = $this->createGamertrophy($gamerInstance, $trophy);
                $this->gamerTrophyModel->create($gamerTrophy, true);
                return new Response('Nagroda jednorazowa przyznana');
            } elseif ($trophyCount != 0) {
                return new Response('Posiadasz już tą nagrodę jednorazową.');
            } else {
                return new Response('Nagroda jednorazowa nie przyznana');
            }
        } elseif ($trophy->getTrophyType()->getId() == 2/* Cykliczna */) {
            if ($assertion == true) {
                $gamerTrophy = $this->createGamertrophy($gamerInstance, $trophy);
                $this->gamerTrophyModel->create($gamerTrophy, true);
                return new Response("Nagroda cykliczna przyznana. Obecnie masz $cyclicCount nagród tego typu.");
            } else {
                return new Response('Nagroda cykliczna nie przyznana.');
            }
        }
    }

    /**
     * Based on the data provided as arguments, decides if assertion is true.
     * 
     * @param string $context
     * @param string $operator
     * @param integer $value
     * @param integer $contextValue
     * @return boolean
     */
    public function assertion($context, $operator, $value, $contextValue)
    {
        $ruler = new Ruler();
        $cont = new Context();
        $rule = $context . " " . $operator . " " . $value;
        $cont[$context] = $contextValue;
        return $ruler->assert($rule, $cont);
    }

    /**
     * Counts trophies of the given type for a specified user.
     * 
     * @param Gamerinstance $gamerInstance
     * @param Trophy $trophy
     * @return integer
     */
    public function countTrophies(\TMSolution\GamificationBundle\Entity\Gamerinstance $gamerInstance, \TMSolution\GamificationBundle\Entity\Trophy $trophy)
    {
        $trophiesArray = $this->gamerTrophyModel->findBy(['gamerinstance' => $gamerInstance, 'trophy' => $trophy]);
        $count = count($trophiesArray);
        return $count;
    }

    //doubled with addGamertrophy
    /**
     * Creates an Gamertrophy with gamerInstance and trophy given.
     * 
     * @param Gamerinstance $gamerInstance
     * @param Trophy $trophy
     * @return Gamertrophy
     */
    public function createGamertrophy(\TMSolution\GamificationBundle\Entity\Gamerinstance $gamerInstance, \TMSolution\GamificationBundle\Entity\Trophy $trophy)
    {
        $gamerTrophy = new Gamertrophy();
        $gamerTrophy->setDate(new \DateTime('NOW'))
                ->setGamerinstance($gamerInstance)
                ->setTrophy($trophy);
        $result = $this->gamerTrophyModel->create($gamerTrophy, true);
        return $result;
    }

    /**
     * Counts cyclic trophies for the specified user.
     * 
     * WARNING! 
     * The kind of trophy (here - cyclic) derives from it's id in the original test database.
     * This may change in the final version.
     * 
     * @param Gamerinstance $gamerInstance
     * @return integer
     */
    public function countCyclicTrophies(\TMSolution\GamificationBundle\Entity\Gamerinstance $gamerInstance)
    {
        $cyclicTrophy = $this->trophyModel->findOneById(2);
        $cyclicTrophies = $this->countTrophies($gamerInstance, $cyclicTrophy);
        return $cyclicTrophies;
    }

}

//--objecttrophy - gamerinstance - poprawic musi sie nazywac tak samo
//--objectinstancetropohy - zamiast gamertrophy
//--objectinstance - przyjazna nazwa? - np.gamer
//--event dopisac date
//--usecase - wybudowac test na mocku - user zaklada event i dostaje nagrode
//test wsdl
//--event = ma byc gamerid (czyli gamerinstanceid)
//test dla modelu jako calosc - nie na poszczegolne metody, a takze by byl uniwersalny dla modelow inncyh  takze.
//??test jednostkowe symfony dla encji
//--testowanie googla - ksiazka na stanie firmy