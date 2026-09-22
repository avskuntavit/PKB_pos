<script setup lang="ts">
/**
 * วงกลมหมุน บอกว่าระบบกำลังทำงานอยู่
 *
 * ใช้ border หมุนแทนไอคอน svg เพราะเบากว่าและยืดขนาดตาม class ที่ส่งมาได้
 * สีมาจาก border-current จึงกลืนกับปุ่มที่ครอบมันอยู่เสมอ ไม่ต้องส่งสีเข้ามา
 *
 * aria-hidden เพราะสถานะจริงบอกผ่าน aria-busy ของปุ่มอยู่แล้ว
 * ถ้าปล่อยให้โปรแกรมอ่านหน้าจออ่านตัวหมุนด้วย ผู้ใช้จะได้ยินซ้ำสองรอบ
 *
 * เครื่องที่ตั้งค่าลดการเคลื่อนไหวไว้ หมุนช้าลงแทนที่จะหยุดนิ่ง
 * เพราะถ้าหยุดนิ่งจะกลายเป็นจุดกลม ๆ ที่ไม่ได้บอกอะไรเลย
 */
</script>

<template>
    <span
        class="inline-block shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:[animation-duration:2.5s]"
        aria-hidden="true"
    />
</template>
