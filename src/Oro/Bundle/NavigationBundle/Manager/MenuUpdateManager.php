<?php

namespace Oro\Bundle\NavigationBundle\Manager;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;

use Knp\Menu\ItemInterface;

use Oro\Bundle\NavigationBundle\Entity\MenuUpdateInterface;
use Oro\Bundle\NavigationBundle\Exception\NotFoundParentException;
use Oro\Bundle\NavigationBundle\JsTree\MenuUpdateTreeHandler;
use Oro\Bundle\NavigationBundle\Menu\Helper\MenuUpdateHelper;
use Oro\Bundle\NavigationBundle\Provider\BuilderChainProvider;
use Oro\Bundle\NavigationBundle\Utils\MenuUpdateUtils;
use Oro\Bundle\ScopeBundle\Entity\Scope;

class MenuUpdateManager
{
    /** @var ManagerRegistry */
    private $managerRegistry;

    /** @var BuilderChainProvider */
    private $builderChainProvider;

    /** @var MenuUpdateHelper */
    private $menuUpdateHelper;

    /** @var string */
    private $entityClass;

    /**
     * @param ManagerRegistry      $managerRegistry
     * @param BuilderChainProvider $builderChainProvider
     * @param MenuUpdateHelper     $menuUpdateHelper
     */
    public function __construct(
        ManagerRegistry $managerRegistry,
        BuilderChainProvider $builderChainProvider,
        MenuUpdateHelper $menuUpdateHelper
    ) {
        $this->managerRegistry = $managerRegistry;
        $this->builderChainProvider = $builderChainProvider;
        $this->menuUpdateHelper = $menuUpdateHelper;
    }

    /**
     * @param string $entityClass
     */
    public function setEntityClass($entityClass)
    {
        $this->entityClass = $entityClass;
    }

    /**
     * Get menu built by BuilderChainProvider
     *
     * @param string $name
     * @param array $options
     *
     * @return ItemInterface
     */
    public function getMenu($name, array $options = [])
    {
        $options = array_merge($options, [
            'ignoreCache' => true
        ]);

        return $this->builderChainProvider->get($name, $options);
    }

    /**
     * Create menu update entity
     *
     * @param Scope $scope
     * @param array $options
     *
     * @return MenuUpdateInterface
     */
    public function createMenuUpdate(Scope $scope, array $options = [])
    {
        /** @var MenuUpdateInterface $entity */
        $entity = new $this->entityClass;
        $entity = $entity->setScope($scope);

        if (isset($options['key'])) {
            $entity->setKey($options['key']);
        }
        $isCustom = isset($options['custom']) && $options['custom'];
        $entity->setCustom($isCustom);
        if (isset($options['menu'])) {
            $menu = $this->builderChainProvider->get($options['menu']);

            $entity->setMenu($options['menu']);

            if (isset($options['parentKey'])) {
                if ($options['parentKey'] === $options['menu']) {
                    $parent = $menu;
                } else {
                    $parent = MenuUpdateUtils::findMenuItem($menu, $options['parentKey']);
                }
                if (!$parent) {
                    throw new NotFoundParentException(sprintf('Parent with "%s" id not found.', $options['parentKey']));
                }
                $entity->setParentKey($options['parentKey']);
            }
        } else {
            throw new \InvalidArgumentException('options["menu"] should be defined.');
        }
        if (isset($options['isDivider']) && $options['isDivider']) {
            $entity->setDivider(true);
            $entity->setDefaultTitle(MenuUpdateTreeHandler::MENU_ITEM_DIVIDER_LABEL);
            $entity->setUri('#');
        }

        return $entity;
    }

    /**
     * Get existing or create new MenuUpdate for specified menu, key and scope
     *
     * @param string $menuName
     * @param string $key
     * @param Scope  $scope
     *
     * @return null|MenuUpdateInterface
     */
    public function findOrCreateMenuUpdate($menuName, $key, Scope $scope)
    {
        /** @var MenuUpdateInterface $update */
        $update = $this->getRepository()->findOneBy([
            'menu' => $menuName,
            'key' => $key,
            'scope' => $scope,
        ]);

        if (!$update) {
            $update = $this->createMenuUpdate($scope, ['key' => $key, 'menu' => $menuName]);
        }

        return $this->getMenuUpdateFromMenu($update, $menuName, $key, $scope);
    }

    /**
     * @param string $menuName
     * @param string $key
     * @param Scope  $scope
     *
     * @return ItemInterface|null
     */
    public function findMenuItem($menuName, $key, Scope $scope)
    {
        $options = [
            'ignoreCache' => true,
            'scope' => $scope
        ];
        $menu = $this->getMenu($menuName, $options);

        return MenuUpdateUtils::findMenuItem($menu, $key);
    }

    /**
     * @param string $menuName
     * @param string $key
     * @param Scope  $scope
     */
    public function showMenuItem($menuName, $key, Scope $scope)
    {
        $item = MenuUpdateUtils::findMenuItem($this->getMenu($menuName), $key);
        if ($item !== null) {
            $update = $this->findOrCreateMenuUpdate($menuName, $item->getName(), $scope);
            $update->setActive(true);
            $this->getEntityManager()->persist($update);

            $this->showMenuItemParents($menuName, $item, $scope);
            $this->showMenuItemChildren($menuName, $item, $scope);

            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param string        $menuName
     * @param ItemInterface $item
     * @param Scope         $scope
     */
    private function showMenuItemParents($menuName, $item, Scope $scope)
    {
        $parent = $item->getParent();
        if ($parent !== null && !$parent->isDisplayed()) {
            $update = $this->findOrCreateMenuUpdate($menuName, $parent->getName(), $scope);
            $update->setActive(true);
            $this->getEntityManager()->persist($update);

            $this->showMenuItemParents($menuName, $parent, $scope);
        }
    }

    /**
     * @param string        $menuName
     * @param ItemInterface $item
     * @param Scope         $scope
     */
    private function showMenuItemChildren($menuName, $item, Scope $scope)
    {
        /** @var ItemInterface $child */
        foreach ($item->getChildren() as $child) {
            $update = $this->findOrCreateMenuUpdate($menuName, $child->getName(), $scope);
            $update->setActive(true);
            $this->getEntityManager()->persist($update);

            $this->showMenuItemChildren($menuName, $child, $scope);
        }
    }

    /**
     * @param string $menuName
     * @param string $key
     * @param Scope  $scope
     */
    public function hideMenuItem($menuName, $key, Scope $scope)
    {
        $item = MenuUpdateUtils::findMenuItem($this->getMenu($menuName), $key);
        if ($item !== null) {
            $update = $this->findOrCreateMenuUpdate($menuName, $item->getName(), $scope);
            $update->setActive(false);
            $this->getEntityManager()->persist($update);

            $this->hideMenuItemChildren($menuName, $item, $scope);

            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param string        $menuName
     * @param ItemInterface $item
     * @param Scope         $scope
     */
    private function hideMenuItemChildren($menuName, ItemInterface $item, Scope $scope)
    {
        /** @var ItemInterface $child */
        foreach ($item->getChildren() as $child) {
            $update = $this->findOrCreateMenuUpdate($menuName, $child->getName(), $scope);
            $update->setActive(false);
            $this->getEntityManager()->persist($update);

            $this->hideMenuItemChildren($menuName, $child, $scope);
        }
    }

    /**
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @param string $menuName
     * @param string $key
     * @param Scope  $scope
     * @param string $parentKey
     * @param int    $position
     *
     * @return MenuUpdateInterface[]
     */
    public function moveMenuItem($menuName, $key, Scope $scope, $parentKey, $position)
    {
        $currentUpdate = $this->findOrCreateMenuUpdate($menuName, $key, $scope);

        $parent = $this->findMenuItem($menuName, $parentKey, $scope);
        $currentUpdate->setParentKey($parent ? $parent->getName() : null);

        $i = 0;
        $order = [];
        $parent = !$parent ? $this->getMenu($menuName) : $parent;

        /** @var ItemInterface $child */
        foreach ($parent->getChildren() as $child) {
            if ($position == $i++) {
                $currentUpdate->setPriority($i++);
            }

            if ($child->getName() != $key) {
                $order[$i] = $child;
            }
        }

        $updates = array_merge(
            [$currentUpdate],
            $this->getReorderedMenuUpdates($menuName, $order, $scope)
        );

        return $updates;
    }

    /**
     * @param Scope  $scope
     * @param string $menu
     */
    public function deleteMenuUpdates($scope, $menu = null)
    {
        $criteria['scope'] = $scope;

        if ($menu) {
            $criteria['menu'] = $menu;
        }

        $menuUpdates = $this->getRepository()->findBy($criteria);

        foreach ($menuUpdates as $menuUpdate) {
            $this->getEntityManager()->remove($menuUpdate);
        }

        $this->getEntityManager()->flush($menuUpdates);
    }

    /**
     * Get list of menu update with new position
     *
     * @param string          $menuName
     * @param ItemInterface[] $orderedChildren
     * @param Scope           $scope
     *
     * @return MenuUpdateInterface[]
     */
    private function getReorderedMenuUpdates($menuName, $orderedChildren, Scope $scope)
    {
        $order = [];
        foreach ($orderedChildren as $priority => $child) {
            $order[$child->getName()] = $priority;
        }

        /** @var MenuUpdateInterface[] $updates */
        $updates = $this->getRepository()->findBy([
            'menu' => $menuName,
            'key' => array_keys($order),
            'scope' => $scope,
        ]);

        foreach ($updates as $update) {
            $update->setPriority($order[$update->getKey()]);
            unset($orderedChildren[$order[$update->getKey()]]);
        }

        foreach ($orderedChildren as $priority => $child) {
            $update = $this->createMenuUpdate(
                $scope,
                ['key' => $child->getName(), 'menu' => $menuName]
            );
            MenuUpdateUtils::updateMenuUpdate($update, $child, $menuName, $this->menuUpdateHelper);
            $update->setPriority($priority);
            $updates[] = $update;
        }

        return $updates;
    }

    /**
     * @param MenuUpdateInterface $update
     * @param string $menuName
     * @param string $key
     * @param Scope  $scope
     *
     * @return MenuUpdateInterface
     */
    private function getMenuUpdateFromMenu(MenuUpdateInterface $update, $menuName, $key, Scope $scope)
    {
        $item = $this->findMenuItem($menuName, $key, $scope);

        if ($item) {
            MenuUpdateUtils::updateMenuUpdate($update, $item, $menuName, $this->menuUpdateHelper);
        } else {
            $update->setCustom(true);
        }

        return $update;
    }

    /**
     * @return EntityRepository
     */
    public function getRepository()
    {
        return $this->getEntityManager()->getRepository($this->entityClass);
    }

    /**
     * @return EntityManager
     */
    private function getEntityManager()
    {
        return $this->managerRegistry->getManagerForClass($this->entityClass);
    }
}
