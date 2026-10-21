<script setup lang="ts">import TicketForm from './TicketForm.vue'; defineProps<{ customer: { id:number; name:string; customer_number:string }; ticket:any; statuses:string[]; priorities:string[]; users:{id:number;name:string}[] }>()</script>
<template><TicketForm v-bind="$props" editing /></template>
